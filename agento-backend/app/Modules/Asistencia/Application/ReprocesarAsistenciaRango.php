<?php

namespace App\Modules\Asistencia\Application;

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
    ) {}

    /**
     * @param  array<int, int>|null  $colaboradorIds
     * @return array{procesados: int, eliminados_anteriores_ingreso: int, omitidos_sin_rol_rotativo: int}
     */
    public function ejecutar(
        Empresa $empresa,
        int $usuarioId,
        string $fechaDesde,
        string $fechaHasta,
        ?array $colaboradorIds,
        ?string $motivo,
    ): array {
        $this->periodos->asegurarRangoEditable($empresa->id, $fechaDesde, $fechaHasta);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
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
                try {
                    $this->procesador->procesar($colaborador, $fecha);
                    $procesados++;
                } catch (HttpExceptionInterface $e) {
                    // Horario rotativo sin rol declarado para esta fecha —
                    // se omite ese día puntual, no se aborta el resto del
                    // reprocesamiento (Sección: rotativos, cero inferencia).
                    $omitidosSinRolRotativo++;
                }
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
            $empresa->id, $usuarioId, 'asistencia_reprocesada', 'rango_asistencia',
            $motivo ?? 'Reprocesamiento manual', null,
            ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fechaHasta, 'colaborador_ids' => $colaboradorIds],
        );

        return [
            'procesados' => $procesados,
            'eliminados_anteriores_ingreso' => $eliminadosAnterioresIngreso,
            'omitidos_sin_rol_rotativo' => $omitidosSinRolRotativo,
        ];
    }
}
