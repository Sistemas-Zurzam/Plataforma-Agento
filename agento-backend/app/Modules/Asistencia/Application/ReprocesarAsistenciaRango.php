<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaImportacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReprocesarAsistenciaRango
{
    public function __construct(
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaAuditoriaService $auditoria,
        private readonly AsignarDescansoFlexibleSemanal $descansoFlexible,
        private readonly FechaOperativa $fechaOperativa,
        private readonly DescartarCambiosManualesAsistencia $descartarCambiosManuales,
    ) {}

    /**
     * Rango de fechas y colaboradores de la última importación de
     * marcaciones completada — lo que recalcula "Recalcular última
     * importación". Igual que la importación, solo incluye a los
     * colaboradores presentes en ese archivo: a uno cuyo archivo aún no se
     * cargó no se le deben generar faltas.
     *
     * @return array{fecha_desde: string, fecha_hasta: string, colaborador_ids: list<int>}|null
     */
    public function rangoUltimaImportacion(Empresa $empresa): ?array
    {
        $importacion = AsistenciaImportacion::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'completada')
            ->whereNotNull('fecha_minima')
            ->latest('id')
            ->first();

        return $importacion ? [
            'fecha_desde' => $importacion->fecha_minima->toDateString(),
            'fecha_hasta' => $importacion->fecha_maxima->toDateString(),
            'colaborador_ids' => $importacion->marcaciones()->whereNotNull('colaborador_id')
                ->distinct()->pluck('asistencia_marcaciones.colaborador_id')->map(fn ($id) => (int) $id)->values()->all(),
        ] : null;
    }

    /**
     * Sin $descartarCambiosManuales el reproceso conserva las decisiones de
     * RR.HH. (marcaciones manuales, estados forzados, planificación,
     * incidencias y horas extra resueltas). Con él ("Reprocesar todo"),
     * DescartarCambiosManualesAsistencia las revierte antes de reprocesar.
     *
     * @param  array<int, int>|null  $colaboradorIds
     * @return array{procesados: int, eliminados_anteriores_ingreso: int, omitidos_sin_rol_rotativo: int, conservados_por_ajuste_manual: int, cambios_descartados: ?array}
     */
    public function ejecutar(
        Empresa $empresa,
        int $usuarioId,
        string $fechaDesde,
        string $fechaHasta,
        ?array $colaboradorIds,
        ?string $motivo,
        bool $descartarCambiosManuales = false,
    ): array {
        $this->periodos->asegurarRangoEditable($empresa->id, $fechaDesde, $fechaHasta);

        // Un cesado sigue necesitando su asistencia hasta la fecha de cese:
        // su último mes se paga en la boleta normal del ciclo.
        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where(fn ($query) => $query->where('activo', true)->orWhereDate('fecha_cese', '>=', $fechaDesde))
            ->when($colaboradorIds, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        $inicio = Carbon::parse($fechaDesde);
        // Un reproceso solicitado hasta fin de mes no puede fabricar faltas
        // para jornadas que todavía no existen. Las fechas posteriores se
        // procesarán cuando llegue su Excel semanal o en el próximo reproceso.
        $fin = Carbon::parse($fechaHasta)->min($this->fechaOperativa->hoy());
        $procesados = 0;
        $eliminadosAnterioresIngreso = 0;
        $omitidosSinRolRotativo = 0;

        $cambiosDescartados = $descartarCambiosManuales
            ? $this->descartarCambiosManuales->ejecutar(
                $empresa, $usuarioId, $colaboradores->modelKeys(), $fechaDesde, $fechaHasta, $motivo ?? 'Reprocesar todo',
            )
            : null;

        // Días ajustados manualmente: procesar() los conserva; aquí solo se
        // cuentan para informarlo (después de Restablecer ya no quedan).
        $ajustados = AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('colaborador_id', $colaboradores->modelKeys())
            ->whereDate('fecha', '>=', $inicio->toDateString())->whereDate('fecha', '<=', $fin->toDateString())
            ->whereNotNull('ajustado_manualmente_at')
            ->get(['colaborador_id', 'fecha'])
            ->map(fn (AsistenciaResultadoDiario $r) => $r->colaborador_id.'|'.$r->fecha->toDateString())
            ->flip();
        $conservadosPorAjusteManual = 0;

        foreach ($colaboradores as $colaborador) {
            // Si RR.HH. corrigió la fecha de ingreso hacia adelante, los
            // resultados ya persistidos antes de esa fecha no se vuelven a
            // procesar, pero tampoco pueden quedar visibles ni alimentar
            // Nómina. Se eliminan solo dentro del rango solicitado. Las
            // incidencias/HE/pivotes caen por FK cascade; las marcaciones
            // crudas se conservan como evidencia de origen.
            $ultimoDiaAnteriorIngreso = $colaborador->fecha_ingreso->copy()->subDay();
            if ($inicio->lte($ultimoDiaAnteriorIngreso)) {
                $hastaLimpiar = $fin->copy()->min($ultimoDiaAnteriorIngreso);
                $eliminadosAnterioresIngreso += AsistenciaResultadoDiario::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereBetween('fecha', [$inicio->toDateString(), $hastaLimpiar->toDateString()])
                    ->delete();
            }

            for ($fecha = $inicio->copy(); $fecha->lte($fin); $fecha->addDay()) {
                if ($fecha->lt($colaborador->fecha_ingreso->copy()->startOfDay())) {
                    continue;
                }
                if ($colaborador->fecha_cese && $fecha->gt($colaborador->fecha_cese->copy()->startOfDay())) {
                    break; // después del último día trabajado no hay jornada
                }
                try {
                    $this->procesador->procesar($colaborador, $fecha);
                    $ajustados->has($colaborador->id.'|'.$fecha->toDateString()) ? $conservadosPorAjusteManual++ : $procesados++;
                } catch (HttpExceptionInterface $e) {
                    // Horario rotativo sin rol declarado para esta fecha —
                    // se omite ese día puntual, no se aborta el resto del
                    // reprocesamiento (Sección: rotativos, cero inferencia).
                    $omitidosSinRolRotativo++;
                }
            }
        }

        // Días fuera del rango cuya salida de madrugada se desvinculó al
        // descartar cambios manuales: deben recuperar esa marcación.
        $hoy = $this->fechaOperativa->hoy();
        foreach ($cambiosDescartados['fechas_adicionales'] ?? [] as $adicional) {
            $fecha = Carbon::parse($adicional['fecha']);
            $colaborador = $colaboradores->firstWhere('id', $adicional['colaborador_id']);
            if (! $colaborador || $fecha->gt($hoy) || ($colaborador->fecha_cese && $fecha->gt($colaborador->fecha_cese->copy()->startOfDay()))) {
                continue;
            }
            try {
                $this->procesador->procesar($colaborador, $fecha);
                $procesados++;
            } catch (HttpExceptionInterface $e) {
                // Período protegido o rotativo sin rol: el día conserva su
                // resultado anterior hasta un próximo reproceso.
            }
        }

        // Descanso semanal flexible automático (opt-in) — corre acá, DESPUÉS
        // del reprocesamiento día a día de arriba, porque necesita que ya
        // exista un AsistenciaResultadoDiario por cada fecha (incluso
        // 'dia_sin_clasificar') para poder clasificar la semana. No requiere
        // un AsistenciaPeriodo real guardado: procesarPeriodo() solo lee las
        // fechas del objeto, así que se le pasa una instancia efímera con el
        // rango pedido — así RR.HH. no necesita crear/gestionar un período
        // formal solo para que esto se aplique.
        if ($empresa->descanso_flexible_automatico) {
            $periodoEfimero = new AsistenciaPeriodo(['fecha_inicio' => $inicio, 'fecha_fin' => $fin]);
            $this->descansoFlexible->procesarPeriodo($empresa, $periodoEfimero, $usuarioId, $colaboradorIds);
        }

        $this->auditoria->registrar(
            $empresa->id, $usuarioId, $descartarCambiosManuales ? 'asistencia_reprocesada_total' : 'asistencia_reprocesada', 'rango_asistencia',
            $motivo ?? 'Reprocesamiento manual', null,
            ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fechaHasta, 'colaborador_ids' => $colaboradorIds],
        );

        if ($cambiosDescartados !== null) {
            unset($cambiosDescartados['fechas_adicionales']);
        }

        return [
            'procesados' => $procesados,
            'eliminados_anteriores_ingreso' => $eliminadosAnterioresIngreso,
            'omitidos_sin_rol_rotativo' => $omitidosSinRolRotativo,
            'conservados_por_ajuste_manual' => $conservadosPorAjusteManual,
            'cambios_descartados' => $cambiosDescartados,
        ];
    }
}
