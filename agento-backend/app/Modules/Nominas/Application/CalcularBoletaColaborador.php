<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Nominas\Domain\RegimenCalculatorFactory;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Configuracion\Models\ReglaDescuentoTardanza;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Orquesta el cálculo de UNA boleta para un colaborador dentro de un ciclo,
 * siguiendo el pseudocódigo de la Sección 2.14 del encargo:
 * asistencia + parámetros vigentes + motor del régimen → ingresos/egresos/
 * aportaciones → neto + snapshots de auditoría. No persiste nada —
 * BoletaService decide cómo guardar el resultado (transacción, versión).
 */
class CalcularBoletaColaborador
{
    public function __construct(private readonly AsistenciaOperacionService $asistenciaOperacion) {}

    /**
     * @param  int|null  $cicloId  Necesario para recoger comisiones/bonos del período
     *   (colaborador_conceptos_periodo) y para excluir la boleta del propio
     *   ciclo del histórico de gratificaciones/renta anual. Null solo en
     *   contexto de prueba manual sin ciclo real todavía creado.
     * @return array{
     *   regimen_laboral: string, sueldo_basico: float, dias_pagados: float,
     *   asistencia_procesada: bool, dias_falta: float, minutos_tardanza: int,
     *   ingresos: array<int, array>, egresos: array<int, array>, aportaciones: array<int, array>,
     *   total_ingresos: float, total_egresos: float, total_aportaciones: float, neto_a_pagar: float,
     *   snapshot_parametros_version: string, snapshot_reglas_version: string, alertas: array<int, string>,
     * }
     */
    public function calcular(Colaborador $colaborador, string $fechaInicio, string $fechaFin, string $fechaCorte, ?int $cicloId = null): array
    {
        $regimen = $colaborador->regimen_laboral ?: 'General';
        $calculadora = RegimenCalculatorFactory::paraRegimen($regimen);
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $regimen, $fechaCorte);

        $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $remuneracion) {
            throw new RuntimeException("El colaborador #{$colaborador->id} no tiene remuneración vigente a {$fechaCorte}.");
        }

        $sueldoBasico = (float) $remuneracion->salario;
        $alertas = [];

        if ($colaborador->es_trabajador_confianza) {
            // Trabajador de confianza: no importa si faltó ni si tuvo horas
            // extra — se le paga el sueldo básico completo cada período, sin
            // descuento por tardanza ni ingreso por horas extra. Los aportes
            // obligatorios (AFP/ONP, EsSalud, renta 5ta) NO cambian por esto
            // — siguen calculándose normal más abajo, porque dependen del
            // sueldo básico completo, no de esta asistencia "neutral".
            // Tampoco necesita asistencia procesada en absoluto para poder
            // calcularle planilla (ni siquiera el rol de un horario rotativo).
            $asistencia = $this->asistenciaNeutralConfianza();
            $alertas[] = 'Trabajador de confianza — no se aplican descuentos por tardanza ni ingresos por horas extra; se paga el sueldo básico completo.';
        } else {
            $asistencia = $this->obtenerAsistenciaDelPeriodo($colaborador, $fechaInicio, $fechaFin);

            if (! $asistencia['asistencia_procesada']) {
                // El básico/tardanza de este cálculo se basan en 0 faltas y 0
                // minutos de tardanza porque Asistencia todavía no procesó el
                // período — NO porque el colaborador tenga asistencia perfecta.
                // La UI debe mostrar "Sin procesar", nunca el número como si
                // fuera un resultado confirmado.
                $alertas[] = 'Asistencia del período aún no procesada — el cálculo asume 0 faltas y 0 minutos de tardanza hasta que se procese.';
            }
        }
        $ingresos = [];
        $egresos = [];
        $aportaciones = [];

        // --- Ingresos ---
        $basico = $calculadora->calcularBasico($sueldoBasico, $asistencia['dias_falta'], $asistencia['horas_permiso_sin_goce']);
        $diasPagados = $basico['dias_pagados'];

        // SUNAT distingue Remuneración Vacacional (Tabla 22: 0118) de
        // Remuneración/Jornal Básico (0121) — calcularBasico() ya paga el
        // mes completo incluyendo los días de vacaciones (no los descuenta
        // de dias_pagados, a diferencia de una falta), así que acá solo se
        // DESCOMPONE esa misma línea en dos, nunca se suma un monto nuevo
        // encima (evita duplicar sueldo). No se toca PlanillaDependienteCalculator.
        $vacaciones = $this->asistenciaOperacion->vacacionesTomadas($colaborador, $fechaInicio, $fechaFin);
        $diasVacaciones = min($vacaciones['dias'], $diasPagados);

        if ($diasVacaciones > 0) {
            $montoVacacional = round(($sueldoBasico / 30) * $diasVacaciones, 2);
            $ingresos[] = [
                ...$basico['linea'],
                'monto' => round($basico['linea']['monto'] - $montoVacacional, 2),
                'cantidad' => $diasPagados - $diasVacaciones,
                'formula_texto' => $basico['linea']['formula_texto']." — excluye {$diasVacaciones} día(s) de vacaciones tomadas (ver Remuneración Vacacional)",
            ];
            $ingresos[] = [
                'codigo' => 'REMUNERACION_VACACIONAL',
                'monto' => $montoVacacional,
                'base_utilizada' => $sueldoBasico,
                'tasa_aplicada' => null,
                'cantidad' => $diasVacaciones,
                'formula_texto' => "({$sueldoBasico} / 30) × {$diasVacaciones} día(s) de vacaciones tomadas en el período",
            ];
        } else {
            $ingresos[] = $basico['linea'];
        }

        foreach ($calculadora->calcularHorasExtra($sueldoBasico, $asistencia['horas_he25'], $asistencia['horas_he35'], $asistencia['horas_he100'], $parametros) as $linea) {
            $ingresos[] = $linea;
        }

        if ($asignacionFamiliar = $calculadora->calcularAsignacionFamiliar((bool) $colaborador->tiene_hijos_asignacion_familiar, $parametros)) {
            $ingresos[] = $asignacionFamiliar;
        }

        // Las comisiones/bonos/adelantos que RR.HH. registró para este
        // período (colaborador_conceptos_periodo) se enrutan según el TIPO
        // real del concepto en el catálogo — nunca se asume que todo lo
        // manual es un ingreso. Un adelanto de sueldo (tipo=egreso) debe
        // descontar, no sumar (Sección 47).
        $conceptosManuales = $this->conceptosDelPeriodo($colaborador, $cicloId);
        foreach ($conceptosManuales as $manual) {
            if ($manual['tipo'] === 'egreso') {
                $egresos[] = $manual['linea'];
            } else {
                $ingresos[] = $manual['linea'];
            }
        }

        $codigosIngreso = collect($ingresos)->pluck('codigo')->unique()->values();
        $catalogoIngresos = ConceptoRemuneracion::whereIn('codigo', $codigosIngreso)->get()->keyBy('codigo');

        $baseRemunerativa = $this->sumarPorFlag($ingresos, $catalogoIngresos, 'es_remunerativo_laboral');
        $baseAfectaRenta5ta = $this->sumarPorFlag($ingresos, $catalogoIngresos, 'afecta_renta_5ta');

        // --- Egresos ---
        foreach ($calculadora->calcularAporteAfpOnp($colaborador, $baseRemunerativa, $parametros, $fechaCorte) as $linea) {
            $egresos[] = $linea;
        }

        $reglasDescuentoTardanza = ReglaDescuentoTardanza::where('empresa_id', $colaborador->empresa_id)
            ->orderBy('orden')
            ->get(['minutos_desde', 'minutos_hasta', 'tipo', 'valor'])
            ->map(fn ($r) => $r->toArray())
            ->all();
        $tardanza = $calculadora->calcularDescuentoTardanza($sueldoBasico, $asistencia['minutos_tardanza'], $reglasDescuentoTardanza);
        $egresos[] = $tardanza;

        $renta5ta = $this->calcularRenta5ta($colaborador, $baseAfectaRenta5ta, $parametros, $fechaCorte, $cicloId);
        if ($renta5ta) {
            $egresos[] = $renta5ta;
        }

        // --- Aportaciones ---
        // BLOQUEANTE DE DEFINICIÓN NORMATIVA reportado, no resuelto en
        // silencio: la Sección 2.5 dice textualmente que la tardanza "no
        // afecta la base remunerativa de AFP/EsSalud". Pero el caso numérico
        // verificado de la Sección 2.13 SÍ resta la tardanza de la base usada
        // para EsSalud (1,518.75 − 0.83 = 1,517.92) para llegar a S/136.62.
        // Se replica aquí el caso numérico —el propio documento lo marca como
        // el que hay que cuadrar "al centavo"— no el texto. Esta es la única
        // línea a invertir (quitar "- $tardanza['monto']") si se confirma que
        // el texto es la regla correcta.
        $baseEsSalud = $baseRemunerativa - $tardanza['monto'];
        $essalud = $calculadora->calcularEsSalud($baseEsSalud, $parametros, $colaborador->empresa->seguro_salud);
        $aportaciones[] = $essalud['linea'];
        if ($essalud['piso_activado']) {
            $alertas[] = 'Se aplicó el piso legal de EsSalud (9% de la RMV vigente) porque el cálculo sobre la base remunerativa fue menor.';
        }

        $gratificacionesSemestre = $this->gratificacionesPercibidasSemestre($colaborador, $fechaCorte, $cicloId);
        foreach ($calculadora->calcularProvisiones($baseRemunerativa, $gratificacionesSemestre, $parametros) as $linea) {
            $aportaciones[] = $linea;
        }

        $totalIngresos = round(collect($ingresos)->sum('monto'), 2);
        $totalEgresos = round(collect($egresos)->sum('monto'), 2);
        $totalAportaciones = round(collect($aportaciones)->sum('monto'), 2);

        return [
            'regimen_laboral' => $regimen,
            'sueldo_basico' => $sueldoBasico,
            'dias_pagados' => $diasPagados,
            'asistencia_procesada' => $asistencia['asistencia_procesada'],
            'dias_falta' => $asistencia['dias_falta'],
            'minutos_tardanza' => $asistencia['minutos_tardanza'],
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'aportaciones' => $aportaciones,
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'total_aportaciones' => $totalAportaciones,
            'neto_a_pagar' => round($totalIngresos - $totalEgresos, 2),
            'snapshot_parametros_version' => $parametros['version_id'],
            'snapshot_reglas_version' => 'planilla-dependiente-v1',
            'alertas' => $alertas,
        ];
    }

    /**
     * La clasificación remunerativo/renta-5ta SIEMPRE viene del catálogo
     * ConceptoRemuneracion — nunca de una lista hardcodeada acá (Sección 18).
     */
    private function sumarPorFlag(array $lineas, $catalogo, string $flag): float
    {
        return round(collect($lineas)->sum(function (array $linea) use ($catalogo, $flag) {
            $concepto = $catalogo->get($linea['codigo']);

            return ($concepto && $concepto->{$flag}) ? $linea['monto'] : 0;
        }), 2);
    }

    /**
     * Comisiones/bonos que RR.HH. ingresó para este colaborador+ciclo antes
     * de calcular (colaborador_conceptos_periodo, Sección 46).
     *
     * @return array<int, array{tipo: string, linea: array}>
     */
    private function conceptosDelPeriodo(Colaborador $colaborador, ?int $cicloId): array
    {
        if (! $cicloId) {
            return [];
        }

        return ColaboradorConceptoPeriodo::where('ciclo_id', $cicloId)
            ->where('colaborador_id', $colaborador->id)
            ->with('concepto')
            ->get()
            ->map(fn (ColaboradorConceptoPeriodo $item) => [
                'tipo' => $item->concepto->tipo,
                'linea' => [
                    'codigo' => $item->concepto->codigo,
                    'monto' => (float) $item->monto,
                    'base_utilizada' => null,
                    'tasa_aplicada' => null,
                    'cantidad' => null,
                    'formula_texto' => 'Monto ingresado por RR.HH. para este período'.($item->motivo ? " — {$item->motivo}" : ''),
                    // Si RR.HH. eligió una clasificación PLAME concreta
                    // (BONIFICACION/BONO_NO_REMUNERATIVO genéricos no la
                    // tienen por defecto), el snapshot debe usar ESE código,
                    // no el del concepto motor.
                    'concepto_definicion_id' => $item->concepto_definicion_id,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Renta de 5ta — proyección simplificada (Sección 2.11): NO reconstruye
     * ingresos de un empleador anterior en el mismo año ni proyecta
     * gratificaciones futuras con precisión; usa el histórico de boletas
     * propias del año en curso y una proyección plana de gratificación si el
     * régimen la tiene. Documentado como PENDIENTE FUNCIONAL para la
     * liquidación por cese (que sí necesita la lista completa de conceptos
     * inafectos del art. 18° LIR, distinta de esta).
     */
    private function calcularRenta5ta(Colaborador $colaborador, float $ingresoMensualAfectoRenta5ta, array $parametros, string $fechaCorte, ?int $cicloId): ?array
    {
        if (empty($parametros['tramos_renta_5ta'])) {
            return null;
        }

        $fecha = Carbon::parse($fechaCorte);
        $mesesRestantes = 13 - $fecha->month; // incluye el mes actual

        $ingresosYaPercibidos = BoletaConcepto::whereHas('boleta', function ($q) use ($colaborador, $fecha, $cicloId) {
            $q->where('colaborador_id', $colaborador->id)
                ->where('es_version_vigente', true)
                ->whereYear('calculado_at', $fecha->year)
                ->when($cicloId, fn ($q2) => $q2->where('ciclo_id', '!=', $cicloId));
        })
            ->where('afecta_renta_5ta', true)
            ->sum('monto');

        $gratificacionProyectada = $parametros['tasa_gratificacion'] > 0
            ? round(($ingresoMensualAfectoRenta5ta / 6) * $parametros['tasa_gratificacion'] * 2, 2)
            : 0.0;

        $ingresoAnualProyectado = (float) $ingresosYaPercibidos
            + ($ingresoMensualAfectoRenta5ta * $mesesRestantes)
            + $gratificacionProyectada;

        $deduccion = $parametros['deduccion_5ta_uit'] * $parametros['uit'];
        $rentaNetaAnual = max(0, $ingresoAnualProyectado - $deduccion);

        $impuestoAnual = 0.0;
        foreach ($parametros['tramos_renta_5ta'] as $tramo) {
            $limiteInferior = $tramo['limite_inferior_uit'] * $parametros['uit'];
            $limiteSuperior = $tramo['limite_superior_uit'] !== null ? $tramo['limite_superior_uit'] * $parametros['uit'] : null;

            if ($rentaNetaAnual <= $limiteInferior) {
                continue;
            }

            $baseTramo = $limiteSuperior !== null ? min($rentaNetaAnual, $limiteSuperior) - $limiteInferior : $rentaNetaAnual - $limiteInferior;
            $impuestoAnual += $baseTramo * $tramo['tasa'];
        }

        if ($impuestoAnual <= 0) {
            return null;
        }

        $retencionMensual = round($impuestoAnual / $mesesRestantes, 2);

        return [
            'codigo' => 'RENTA_5TA',
            'monto' => $retencionMensual,
            'base_utilizada' => round($rentaNetaAnual, 2),
            'tasa_aplicada' => null,
            'cantidad' => null,
            'formula_texto' => "Proyección anual {$ingresoAnualProyectado} − {$deduccion} (deducción {$parametros['deduccion_5ta_uit']} UIT) = {$rentaNetaAnual} renta neta → impuesto anual {$impuestoAnual} / {$mesesRestantes} meses restantes",
        ];
    }

    private function gratificacionesPercibidasSemestre(Colaborador $colaborador, string $fechaCorte, ?int $cicloId): float
    {
        $fecha = Carbon::parse($fechaCorte);
        $inicioSemestre = $fecha->month <= 6 ? $fecha->copy()->startOfYear() : Carbon::create($fecha->year, 7, 1);

        $conceptoId = ConceptoRemuneracion::where('codigo', 'GRATIFICACION_LEGAL')->value('id');
        if (! $conceptoId) {
            return 0.0;
        }

        return (float) BoletaConcepto::where('concepto_id', $conceptoId)
            ->whereHas('boleta', function ($q) use ($colaborador, $inicioSemestre, $fecha, $cicloId) {
                $q->where('colaborador_id', $colaborador->id)
                    ->where('es_version_vigente', true)
                    ->whereDate('calculado_at', '>=', $inicioSemestre->toDateString())
                    ->whereDate('calculado_at', '<=', $fecha->toDateString())
                    ->when($cicloId, fn ($q2) => $q2->where('ciclo_id', '!=', $cicloId));
            })
            ->sum('monto');
    }

    /**
     * @return array{asistencia_procesada: bool, dias_falta: float, horas_permiso_sin_goce: float, minutos_tardanza: int, horas_he25: float, horas_he35: float, horas_he100: float}
     */
    private function asistenciaNeutralConfianza(): array
    {
        return [
            'asistencia_procesada' => true,
            'dias_falta' => 0.0,
            'horas_permiso_sin_goce' => 0.0,
            'minutos_tardanza' => 0,
            'horas_he25' => 0.0,
            'horas_he35' => 0.0,
            'horas_he100' => 0.0,
        ];
    }

    /**
     * @return array{dias_falta: float, horas_permiso_sin_goce: float, minutos_tardanza: int, horas_he25: float, horas_he35: float, horas_he100: float}
     */
    private function obtenerAsistenciaDelPeriodo(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $resultados = AsistenciaResultadoDiario::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->get();

        $this->verificarRolRotativoCompleto($colaborador, $fechaInicio, $fechaFin, $resultados);

        $horasPermisoSinGoce = AsistenciaPermiso::where('colaborador_id', $colaborador->id)
            ->where('estado', 'aprobado')
            ->where('con_goce', false)
            ->where('fecha_inicio', '<=', $fechaFin)
            ->where('fecha_fin', '>=', $fechaInicio)
            ->get()
            ->sum(function (AsistenciaPermiso $permiso) use ($fechaInicio, $fechaFin) {
                $desde = $permiso->fecha_inicio->max(Carbon::parse($fechaInicio));
                $hasta = $permiso->fecha_fin->min(Carbon::parse($fechaFin));

                return max(0, $desde->diffInDays($hasta) + 1) * 8;
            });

        return [
            'asistencia_procesada' => $resultados->isNotEmpty(),
            'dias_falta' => (float) $resultados->where('estado', 'falta')->count(),
            'horas_permiso_sin_goce' => (float) $horasPermisoSinGoce,
            'minutos_tardanza' => (int) $resultados->sum('minutos_tardanza'),
            'horas_he25' => round($resultados->sum('minutos_extra_25') / 60, 2),
            'horas_he35' => round($resultados->sum('minutos_extra_35') / 60, 2),
            'horas_he100' => round($resultados->sum('minutos_extra_100') / 60, 2),
        ];
    }

    /**
     * Un horario rotativo nunca calcula planilla con huecos — si falta
     * declarar el rol de algún día del período, se rechaza el cálculo
     * completo de este colaborador en vez de omitir esos días en silencio
     * (Sección: rotativos, cero inferencia — un día sin rol no es lo mismo
     * que un día sin marcaciones).
     */
    private function verificarRolRotativoCompleto(Colaborador $colaborador, string $fechaInicio, string $fechaFin, $resultados): void
    {
        $esRotativo = ColaboradorHorarioAsignacion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaFin)
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $fechaInicio))
            ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo'))
            ->exists();

        if (! $esRotativo) {
            return;
        }

        $desde = Carbon::parse(max($fechaInicio, $colaborador->fecha_ingreso->toDateString()));
        $hasta = Carbon::parse($fechaFin);
        if ($desde->gt($hasta)) {
            return;
        }

        $fechasConResultado = $resultados->pluck('fecha')->map(fn (Carbon $fecha) => $fecha->toDateString())->flip();

        for ($fecha = $desde->copy(); $fecha->lte($hasta); $fecha->addDay()) {
            if (! $fechasConResultado->has($fecha->toDateString())) {
                throw new RuntimeException(
                    "El colaborador #{$colaborador->id} tiene horario rotativo y le falta declarar el rol de turnos del {$fecha->toDateString()} — no se puede calcular su planilla hasta que se complete.",
                );
            }
        }
    }
}
