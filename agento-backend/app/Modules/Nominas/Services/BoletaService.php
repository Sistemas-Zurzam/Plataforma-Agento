<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Jobs\CalcularPlanillaJob;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BoletaService
{
    public function __construct(
        private readonly CalcularBoletaColaborador $calculador,
        private readonly CalcularReciboHonorarios $calculadorHonorarios,
        private readonly IncidenciasPendientesNominaService $incidenciasPendientes,
    ) {}

    /**
     * @param  string|null  $tipo  'planilla' | 'honorarios' | null (ambos) —
     *   filtro de presentación; ambos tipos se calculan con motores
     *   distintos pero se listan y pagan desde la misma tabla (Sección
     *   acordada con el usuario: una sola vista, dos motores).
     * @param  string|null  $busqueda  Filtra por nombre/apellido/cargo del
     *   colaborador — mismo criterio que ColaboradorService::listar().
     * @return LengthAwarePaginator<int, Boleta>
     */
    public function listar(Empresa $empresa, CicloRemunerativo $ciclo, int $perPage = 25, ?string $tipo = null, ?string $busqueda = null): LengthAwarePaginator
    {
        $this->verificarPertenencia($empresa, $ciclo);

        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->when($tipo === 'honorarios', fn ($q) => $q->where('regimen_laboral_snapshot', 'Locacion de Servicios'))
            ->when($tipo === 'planilla', fn ($q) => $q->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios'))
            ->when($busqueda, fn ($q) => $q->whereHas('colaborador', fn ($qq) => $qq->where(function ($qq) use ($busqueda) {
                $qq->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('cargo', 'like', "%{$busqueda}%");
            })))
            ->with(['colaborador.empresa'])
            ->orderBy('colaborador_id')
            ->paginate($perPage);
    }

    /**
     * Totales del dashboard — SIEMPRE derivados de las boletas ya
     * calculadas (SUM en base de datos), nunca un valor guardado aparte que
     * pueda desincronizarse (Sección 63).
     *
     * @return array{total_colaboradores: int, total_ingresos: float, total_egresos: float, total_aportaciones: float, neto_a_pagar: float, por_estado: array<string, int>}
     */
    public function resumen(Empresa $empresa, CicloRemunerativo $ciclo, ?string $tipo = null, ?string $busqueda = null): array
    {
        $this->verificarPertenencia($empresa, $ciclo);

        $vigentes = Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->when($tipo === 'honorarios', fn ($q) => $q->where('regimen_laboral_snapshot', 'Locacion de Servicios'))
            ->when($tipo === 'planilla', fn ($q) => $q->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios'))
            ->when($busqueda, fn ($q) => $q->whereHas('colaborador', fn ($qq) => $qq->where(function ($qq) use ($busqueda) {
                $qq->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('cargo', 'like', "%{$busqueda}%");
            })));

        return [
            'total_colaboradores' => (clone $vigentes)->count(),
            'total_ingresos' => (float) (clone $vigentes)->sum('total_ingresos'),
            'total_egresos' => (float) (clone $vigentes)->sum('total_egresos'),
            'total_aportaciones' => (float) (clone $vigentes)->sum('total_aportaciones'),
            'neto_a_pagar' => (float) (clone $vigentes)->sum('neto_a_pagar'),
            'por_estado' => (clone $vigentes)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado')->all(),
        ];
    }

    public function ver(Empresa $empresa, Boleta $boleta): Boleta
    {
        $this->verificarPertenenciaBoleta($empresa, $boleta);

        return $boleta->load(['colaborador.empresa', 'conceptos.concepto', 'ciclo', 'comprobanteRh']);
    }

    /**
     * "Monto total del servicio" (E20, campo 6) para una boleta de
     * honorarios — se resuelve sin ambigüedad sumando el concepto
     * HONORARIO_BRUTO ya calculado, nunca se duplica el dato en
     * boleta_comprobantes_rh (sección 34 del encargo).
     */
    public function montoTotalServicioRh(Boleta $boleta): float
    {
        return (float) $boleta->conceptos()
            ->whereHas('concepto', fn ($q) => $q->where('codigo', 'HONORARIO_BRUTO'))
            ->sum('monto');
    }

    /**
     * Calcula (o recalcula) la planilla completa de un ciclo. Un período
     * cerrado/pagado no se puede recalcular (Sección 59) — hay que
     * reabrirlo primero. Cada colaborador que falle se registra como
     * "omitida" en vez de tumbar el cálculo de toda la empresa.
     *
     * @return array{procesadas: int, omitidas: array<int, array{colaborador_id: int, motivo: string}>}
     */
    public function calcularPlanilla(Empresa $empresa, CicloRemunerativo $ciclo, int $usuarioId, ?string $motivoRecalculo = null): array
    {
        $this->verificarPertenencia($empresa, $ciclo);

        if (in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Este período está cerrado — no se puede recalcular. Reábrelo primero si necesitas corregirlo.',
            ]);
        }

        $procesadas = 0;
        $omitidas = [];

        $elegibles = $this->colaboradoresElegibles($empresa, $ciclo->fecha_inicio->toDateString(), $ciclo->fecha_fin->toDateString());

        foreach ($elegibles as $colaborador) {
            try {
                $this->calcularBoletaColaborador($ciclo, $colaborador, $usuarioId, $motivoRecalculo);
                $procesadas++;
            } catch (Throwable $e) {
                $omitidas[] = ['colaborador_id' => $colaborador->id, 'motivo' => $e->getMessage()];
            }
        }

        $ciclo->update(['estado' => 'calculado']);

        return ['procesadas' => $procesadas, 'omitidas' => $omitidas];
    }

    /**
     * Encola el cálculo en vez de correrlo dentro del request HTTP — con
     * una planilla de cientos de colaboradores, `calcularPlanilla()` puede
     * tardar más de lo que un navegador espera. Valida todo lo que
     * `calcularPlanilla()` valida ANTES de encolar (para fallar rápido con
     * un mensaje claro), y deja el resultado real para cuando el worker
     * termine — el frontend hace polling de `calculo_estado`.
     */
    public function iniciarCalculoAsync(Empresa $empresa, CicloRemunerativo $ciclo, int $usuarioId, ?string $motivoRecalculo = null): CicloRemunerativo
    {
        $this->verificarPertenencia($empresa, $ciclo);

        if (in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Este período está cerrado — no se puede recalcular. Reábrelo primero si necesitas corregirlo.',
            ]);
        }

        if ($ciclo->calculo_estado === 'en_proceso') {
            throw ValidationException::withMessages([
                'calculo_estado' => 'Ya hay un cálculo en curso para este ciclo.',
            ]);
        }

        $ciclo->update([
            'calculo_estado' => 'en_proceso',
            'calculo_iniciado_at' => now(),
            'calculo_finalizado_at' => null,
            'calculo_resultado' => null,
        ]);

        CalcularPlanillaJob::dispatch($empresa->id, $ciclo->id, $usuarioId, $motivoRecalculo);

        return $ciclo;
    }

    private function calcularBoletaColaborador(CicloRemunerativo $ciclo, Colaborador $colaborador, int $usuarioId, ?string $motivoRecalculo): Boleta
    {
        return DB::transaction(function () use ($ciclo, $colaborador, $usuarioId, $motivoRecalculo) {
            // Único punto de bifurcación entre los dos motores (Sección de
            // Recibos por Honorarios acordada con el usuario): un locador
            // nunca entra a CalcularBoletaColaborador ni a
            // RegimenCalculatorFactory — ambos motores solo comparten esta
            // orquestación de persistencia/versión, no las fórmulas.
            // Se acepta cualquiera de las dos señales (tipo_contrato o
            // régimen_laboral) porque se editan en pantallas distintas
            // (Personas vs. Configuración de planilla) y pueden quedar
            // temporalmente desincronizadas — nunca debe reventar en
            // RegimenCalculatorFactory por esa combinación.
            $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios'
                || $colaborador->regimen_laboral === 'Locacion de Servicios';
            $calculador = $esHonorarios ? $this->calculadorHonorarios : $this->calculador;

            $resultado = $calculador->calcular(
                $colaborador,
                $ciclo->fecha_inicio->toDateString(),
                $ciclo->fecha_fin->toDateString(),
                $ciclo->fecha_corte_asistencia->toDateString(),
                $ciclo->id,
                // V3 Fase 6F.2.3 — únicamente para resolver la RMA de
                // AFP_PRIMA_SEGURO (Fase 6F.2.2); CalcularReciboHonorarios
                // ignora este 6to argumento (su firma no lo declara — PHP
                // no falla por argumentos posicionales de más).
                $ciclo->fecha_pago->toDateString(),
            );

            $versionAnterior = Boleta::where('ciclo_id', $ciclo->id)
                ->where('colaborador_id', $colaborador->id)
                ->where('es_version_vigente', true)
                ->first();

            // Nunca se sobrescribe: la versión anterior se apaga, no se borra
            // ni se pisa — así queda el historial de recálculos (Sección 56).
            $versionAnterior?->update(['es_version_vigente' => false]);

            $boleta = Boleta::create([
                'ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'colaborador_id' => $colaborador->id,
                'version' => ($versionAnterior?->version ?? 0) + 1,
                'es_version_vigente' => true,
                'regimen_laboral_snapshot' => $resultado['regimen_laboral'],
                'sueldo_basico_snapshot' => $resultado['sueldo_basico'],
                'dias_pagados' => $resultado['dias_pagados'],
                'asistencia_procesada' => $resultado['asistencia_procesada'],
                'dias_falta' => $resultado['dias_falta'],
                'minutos_tardanza' => $resultado['minutos_tardanza'],
                'total_ingresos' => $resultado['total_ingresos'],
                'total_egresos' => $resultado['total_egresos'],
                'total_aportaciones' => $resultado['total_aportaciones'],
                'neto_a_pagar' => $resultado['neto_a_pagar'],
                'estado' => 'calculada',
                'snapshot_parametros_version' => $resultado['snapshot_parametros_version'],
                'snapshot_reglas_version' => $resultado['snapshot_reglas_version'],
                'alertas' => $resultado['alertas'],
                'motivo_recalculo' => $motivoRecalculo,
                'calculado_por' => $usuarioId,
                'calculado_at' => now(),
            ]);

            $codigos = collect([...$resultado['ingresos'], ...$resultado['egresos'], ...$resultado['aportaciones']])
                ->pluck('codigo')->unique();
            $conceptos = ConceptoRemuneracion::whereIn('codigo', $codigos)->get()->keyBy('codigo');
            $definicionIds = collect([...$resultado['ingresos'], ...$resultado['egresos'], ...$resultado['aportaciones']])
                ->pluck('concepto_definicion_id')->filter()->unique();
            $definiciones = $definicionIds->isEmpty() ? collect() : ConceptoDefinicionPlame::whereIn('id', $definicionIds)->get()->keyBy('id');

            foreach (['ingresos' => 'ingreso', 'egresos' => 'egreso', 'aportaciones' => 'aportacion'] as $bloque => $tipo) {
                foreach ($resultado[$bloque] as $linea) {
                    $concepto = $conceptos->get($linea['codigo']);
                    if (! $concepto) {
                        continue; // catálogo incompleto para este código — se omite la línea, no la boleta completa
                    }

                    // Si RR.HH. eligió una clasificación PLAME concreta al
                    // registrar el concepto del período (BONIFICACION/
                    // BONO_NO_REMUNERATIVO son demasiado genéricos por sí
                    // solos), el snapshot conserva ESE código específico —
                    // nunca el genérico del concepto motor.
                    $definicion = $definiciones->get($linea['concepto_definicion_id'] ?? null);

                    BoletaConcepto::create([
                        'boleta_id' => $boleta->id,
                        'concepto_id' => $concepto->id,
                        'concepto_definicion_id' => $definicion?->id,
                        'tipo' => $tipo,
                        'es_remunerativo_laboral' => $concepto->es_remunerativo_laboral,
                        'afecta_renta_5ta' => $concepto->afecta_renta_5ta,
                        // Snapshot del código PLAME vigente en el catálogo al
                        // momento del cálculo — si un administrador lo cambia
                        // después (ej. SUNAT reasigna el código), esta boleta
                        // ya calculada conserva el que realmente se usó.
                        'codigo_plame_snapshot' => $definicion?->codigo_plame ?? $concepto->codigo_plame,
                        'base_utilizada' => $linea['base_utilizada'],
                        'tasa_aplicada' => $linea['tasa_aplicada'],
                        'cantidad' => $linea['cantidad'],
                        'monto' => $linea['monto'],
                        // El motor de cálculo (RegimenCalculator/CalcularReciboHonorarios)
                        // no distingue devengado de pagado — hoy Agento no tiene ningún
                        // mecanismo de pago parcial, así que "lo calculado" es, por
                        // definición, tanto lo devengado como lo pagado/descontado. Se
                        // guardan como columnas separadas (no una sola) para que PLAME
                        // (estructura E18/.rem) pueda leerlas de forma independiente el
                        // día que exista una razón real para que diverjan (ej. un ajuste
                        // de regularización), sin tener que migrar el esquema entonces.
                        'monto_devengado' => $linea['monto'],
                        'monto_pagado_descontado' => $linea['monto'],
                        'formula_texto' => $linea['formula_texto'],
                    ]);
                }
            }

            return $boleta->load('conceptos.concepto');
        });
    }

    /**
     * Aprobar es solo un cambio de estado auditado — sin lógica bancaria.
     * Una boleta observada no puede aprobarse sin volver a calcularse
     * primero (evita aprobar un cálculo que ya se sabe incorrecto). Tampoco
     * se aprueba si el colaborador tiene incidencias de asistencia
     * pendientes dentro del período del ciclo — mismo criterio que bloquea
     * el cierre del ciclo (CicloRemunerativoService::cerrar()), pero
     * atajado aquí primero para que RR.HH. se entere al aprobar, no recién
     * al intentar cerrar todo el período.
     */
    public function aprobar(Empresa $empresa, Boleta $boleta, int $usuarioId): Boleta
    {
        $this->verificarPertenenciaBoleta($empresa, $boleta);

        if ($boleta->estado !== 'calculada') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede aprobar una boleta en estado "calculada".',
            ]);
        }

        if ($this->incidenciasPendientesAprobar($empresa, collect([$boleta]))->isNotEmpty()) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede aprobar: el colaborador tiene incidencias de asistencia pendientes dentro del período. Resuélvelas en Gestión de asistencias.',
            ]);
        }

        $boleta->update(['estado' => 'aprobada', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);

        return $boleta;
    }

    /**
     * Detalle (con nombre del colaborador) de las incidencias que hoy
     * bloquearían aprobar estas boletas — pensado para que el frontend lo
     * muestre ANTES de intentar aprobar, individual o masivamente. Agrupa
     * por ciclo porque cada colaborador se revisa contra las fechas del
     * ciclo AL QUE PERTENECE su boleta (en la práctica todas las boletas de
     * un aprobar-masivo comparten el mismo ciclo, porque "Planilla mensual"
     * siempre opera sobre un ciclo a la vez).
     *
     * @param  \Illuminate\Support\Collection<int, Boleta>  $boletas
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Asistencia\Models\AsistenciaIncidencia>
     */
    public function incidenciasPendientesAprobar(Empresa $empresa, \Illuminate\Support\Collection $boletas)
    {
        // groupBy('ciclo_id') es válido tanto en Support\Collection como en
        // Eloquent\Collection — a diferencia de loadMissing(), que solo
        // existe en la segunda. $ciclo se resuelve accediendo a la
        // relación directamente (lazy-load si hace falta): en la práctica
        // esto es como mucho una consulta extra por ciclo distinto, nunca
        // por boleta, porque el groupBy ya las agrupó antes.
        return $boletas->groupBy('ciclo_id')->flatMap(function ($grupo) use ($empresa) {
            $ciclo = $grupo->first()->ciclo;
            if (! $ciclo) {
                return collect();
            }

            return $this->incidenciasPendientes
                ->query($empresa, $grupo->pluck('colaborador_id'), $ciclo->fecha_inicio, $ciclo->fecha_fin)
                ->with('colaborador:id,nombres,apellidos,legajo')
                ->get();
        });
    }

    /**
     * "Pagada" nunca es un badge sin respaldo (Sección 65): exige una
     * referencia de pago real (operación bancaria, constancia, lote). No
     * genera ningún archivo bancario — eso es Pagos Masivos, fuera de
     * alcance de este sprint.
     */
    public function marcarPagada(Empresa $empresa, Boleta $boleta, int $usuarioId, string $referenciaPago): Boleta
    {
        $this->verificarPertenenciaBoleta($empresa, $boleta);

        if ($boleta->estado !== 'aprobada') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede marcar como pagada una boleta previamente aprobada.',
            ]);
        }

        $boleta->update([
            'estado' => 'pagada',
            'pagado_por' => $usuarioId,
            'pagado_at' => now(),
            'referencia_pago' => $referenciaPago,
        ]);

        return $boleta;
    }

    private function verificarPertenenciaBoleta(Empresa $empresa, Boleta $boleta): void
    {
        if ($boleta->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Esta boleta no pertenece a la empresa activa.');
        }
    }

    /**
     * Elegibilidad (Sección 28): activos, ya ingresados a la fecha de fin
     * del período, sin cese antes del inicio. Incluye locadores (Recibos por
     * Honorarios) — calcularBoletaColaborador()/previsualizarPlanilla() los
     * enrutan a CalcularReciboHonorarios en vez de excluirlos.
     *
     * Recibe fechas planas (no un CicloRemunerativo) para poder reutilizarse
     * también desde previsualizarPlanilla(), que no tiene ni necesita un
     * ciclo persistido — evita una segunda copia de este filtro (Sección 98,
     * "no duplicar motores").
     */
    private function colaboradoresElegibles(Empresa $empresa, string $fechaInicio, string $fechaFin)
    {
        return Colaborador::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereDate('fecha_ingreso', '<=', $fechaFin)
            ->where(fn ($query) => $query->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', $fechaInicio))
            ->get();
    }

    /**
     * Previsualización mensual continua (Sección 5 de la documentación
     * funcional, y FEEDBACK V1: "botón de previsualización... permite
     * realizar un cálculo hasta el momento sin la necesidad de abrir un
     * ciclo"). Reutiliza exactamente el mismo motor que calcularPlanilla()
     * (CalcularBoletaColaborador / CalcularReciboHonorarios), pero:
     *   - NO requiere un CicloRemunerativo persistido (cicloId = null).
     *   - NUNCA persiste Boleta ni BoletaConcepto — es de solo lectura.
     *   - NUNCA se confunde con la boleta oficial (no tiene id, no se puede
     *     aprobar/pagar/descargar).
     *
     * @return array<int, array{colaborador_id:int, nombre:string, cargo:?string, sueldo_basico:?float,
     *   asistencia_procesada:bool, dias_falta:?float, minutos_tardanza:?int,
     *   total_ingresos:?float, total_egresos:?float, neto_a_pagar:?float, estado:string, motivo:?string}>
     */
    public function previsualizarPlanilla(Empresa $empresa, string $fechaInicio, string $fechaFin, string $fechaCorte): array
    {
        $filas = [];

        foreach ($this->colaboradoresElegibles($empresa, $fechaInicio, $fechaFin) as $colaborador) {
            $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios'
                || $colaborador->regimen_laboral === 'Locacion de Servicios';
            $calculador = $esHonorarios ? $this->calculadorHonorarios : $this->calculador;

            $fila = [
                'colaborador_id' => $colaborador->id,
                'nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'cargo' => $colaborador->cargo,
            ];

            try {
                $resultado = $calculador->calcular($colaborador, $fechaInicio, $fechaFin, $fechaCorte, null);

                $filas[] = [
                    ...$fila,
                    'sueldo_basico' => $resultado['sueldo_basico'],
                    'asistencia_procesada' => $resultado['asistencia_procesada'],
                    'dias_falta' => $resultado['dias_falta'],
                    'minutos_tardanza' => $resultado['minutos_tardanza'],
                    'total_ingresos' => $resultado['total_ingresos'],
                    'total_egresos' => $resultado['total_egresos'],
                    'neto_a_pagar' => $resultado['neto_a_pagar'],
                    'estado' => 'calculable',
                    'motivo' => null,
                ];
            } catch (Throwable $e) {
                $filas[] = [
                    ...$fila,
                    'sueldo_basico' => null,
                    'asistencia_procesada' => false,
                    'dias_falta' => null,
                    'minutos_tardanza' => null,
                    'total_ingresos' => null,
                    'total_egresos' => null,
                    'neto_a_pagar' => null,
                    'estado' => 'no_calculable',
                    'motivo' => $e->getMessage(),
                ];
            }
        }

        return $filas;
    }

    private function verificarPertenencia(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este ciclo remunerativo no pertenece a la empresa activa.');
        }
    }
}
