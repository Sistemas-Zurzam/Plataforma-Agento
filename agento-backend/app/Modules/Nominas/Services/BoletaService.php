<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
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
    ) {}

    /**
     * @param  string|null  $tipo  'planilla' | 'honorarios' | null (ambos) —
     *   filtro de presentación; ambos tipos se calculan con motores
     *   distintos pero se listan y pagan desde la misma tabla (Sección
     *   acordada con el usuario: una sola vista, dos motores).
     * @return LengthAwarePaginator<int, Boleta>
     */
    public function listar(Empresa $empresa, CicloRemunerativo $ciclo, int $perPage = 25, ?string $tipo = null): LengthAwarePaginator
    {
        $this->verificarPertenencia($empresa, $ciclo);

        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->when($tipo === 'honorarios', fn ($q) => $q->where('regimen_laboral_snapshot', 'Locacion de Servicios'))
            ->when($tipo === 'planilla', fn ($q) => $q->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios'))
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
    public function resumen(Empresa $empresa, CicloRemunerativo $ciclo, ?string $tipo = null): array
    {
        $this->verificarPertenencia($empresa, $ciclo);

        $vigentes = Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->when($tipo === 'honorarios', fn ($q) => $q->where('regimen_laboral_snapshot', 'Locacion de Servicios'))
            ->when($tipo === 'planilla', fn ($q) => $q->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios'));

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

        return $boleta->load(['colaborador.empresa', 'conceptos.concepto', 'ciclo']);
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

        foreach ($this->colaboradoresElegibles($empresa, $ciclo) as $colaborador) {
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

            foreach (['ingresos' => 'ingreso', 'egresos' => 'egreso', 'aportaciones' => 'aportacion'] as $bloque => $tipo) {
                foreach ($resultado[$bloque] as $linea) {
                    $concepto = $conceptos->get($linea['codigo']);
                    if (! $concepto) {
                        continue; // catálogo incompleto para este código — se omite la línea, no la boleta completa
                    }

                    BoletaConcepto::create([
                        'boleta_id' => $boleta->id,
                        'concepto_id' => $concepto->id,
                        'tipo' => $tipo,
                        'es_remunerativo_laboral' => $concepto->es_remunerativo_laboral,
                        'afecta_renta_5ta' => $concepto->afecta_renta_5ta,
                        'base_utilizada' => $linea['base_utilizada'],
                        'tasa_aplicada' => $linea['tasa_aplicada'],
                        'cantidad' => $linea['cantidad'],
                        'monto' => $linea['monto'],
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
     * primero (evita aprobar un cálculo que ya se sabe incorrecto).
     */
    public function aprobar(Empresa $empresa, Boleta $boleta, int $usuarioId): Boleta
    {
        $this->verificarPertenenciaBoleta($empresa, $boleta);

        if ($boleta->estado !== 'calculada') {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede aprobar una boleta en estado "calculada".',
            ]);
        }

        $boleta->update(['estado' => 'aprobada', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);

        return $boleta;
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
     * del ciclo, sin cese antes del inicio. Incluye locadores (Recibos por
     * Honorarios) — calcularBoletaColaborador() los enruta a
     * CalcularReciboHonorarios en vez de excluirlos.
     */
    private function colaboradoresElegibles(Empresa $empresa, CicloRemunerativo $ciclo)
    {
        return Colaborador::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereDate('fecha_ingreso', '<=', $ciclo->fecha_fin)
            ->where(fn ($query) => $query->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', $ciclo->fecha_inicio))
            ->get();
    }

    private function verificarPertenencia(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este ciclo remunerativo no pertenece a la empresa activa.');
        }
    }
}
