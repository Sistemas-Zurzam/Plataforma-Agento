<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\Liquidacion\BasesVariablesLiquidacion;
use App\Modules\Nominas\Application\Liquidacion\ResolverRegimenLiquidacion;
use App\Modules\Nominas\Application\Quinta\AjusteFinalQuintaCese;
use App\Modules\Nominas\Domain\Liquidacion\CalculadoraBeneficiosCese;
use App\Modules\Nominas\Domain\Liquidacion\CalculadoraIndemnizacionDespido;
use App\Modules\Nominas\Domain\Liquidacion\CatalogoConceptosLiquidacion;
use App\Modules\Nominas\Domain\Liquidacion\DiasComerciales;
use App\Modules\Nominas\Domain\RegimenCalculatorFactory;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\LiquidacionCeseConcepto;
use App\Modules\Nominas\Models\QuintaAjusteCese;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Liquidación de beneficios por cese, separada del cese: el cese solo fija
 * hasta qué día se paga (la boleta del ciclo paga ese mes); aquí se liquidan
 * los beneficios acumulados. Dos modos:
 *  - asistido: el sistema propone las líneas y RR.HH. puede ajustar cada una
 *    con motivo;
 *  - manual: sin data histórica, RR.HH. ingresa las líneas calculadas fuera
 *    del sistema con su sustento, para dejar registro y pagarla.
 *
 * Multirrégimen: el régimen se resuelve por período (ResolverRegimenLiquidacion)
 * y cada regla laboral que la norma oficial no resuelve para el caso concreto
 * se devuelve como validación bloqueante con su pregunta y la diferencia en
 * soles de cada opción. Con validaciones abiertas la liquidación se guarda
 * como borrador (calculada) pero no se puede aprobar.
 */
class LiquidacionCeseService
{
    private const LOCACION = 'Locacion de Servicios';

    /** Datos de decisión que RR.HH. puede registrar (con sustento) para resolver una validación. */
    public const DECISIONES = [
        'decision_regimen_superpuesto', 'decision_regimen_vacio', 'decision_tramo_locacion', 'decision_clasificacion_vinculo',
        'decision_mype_sin_remype', 'decision_mype_anterior_remype', 'decision_categoria_mype',
        'decision_cts_semestre_cerrado', 'decision_tope_cts_pequena', 'decision_af_microempresa',
        'decision_faltas_vacaciones', 'decision_indemnizacion_plazo_fijo_mype', 'decision_quinta_boleta_cese',
    ];

    public function __construct(
        private readonly AsistenciaOperacionService $asistencia,
        private readonly ResolverRegimenLiquidacion $regimenes,
        private readonly BasesVariablesLiquidacion $variables,
        private readonly AjusteFinalQuintaCese $quinta,
    ) {}

    /**
     * Cesados de la empresa sin liquidación vigente.
     *
     * @return Collection<int, Colaborador>
     */
    public function pendientes(Empresa $empresa): Collection
    {
        return Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('fecha_cese')
            ->where(fn ($q) => $q->whereNull('regimen_laboral')->orWhere('regimen_laboral', '!=', self::LOCACION))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('liquidaciones_cese')
                ->whereColumn('liquidaciones_cese.colaborador_id', 'colaboradores.id')
                ->where('liquidaciones_cese.es_version_vigente', true)
                ->where('liquidaciones_cese.estado', '!=', 'anulada'))
            ->with(['area:id,nombre'])
            ->orderByDesc('fecha_cese')
            ->get();
    }

    /**
     * Propuesta a la fecha de cese registrada, calculada con los datos del
     * sistema. $datosManuales permite completar o corregir los datos de
     * entrada (sueldo, fecha de ingreso real, gratificación percibida,
     * vacaciones gozadas...) y registrar las decisiones laborales pendientes.
     * Cada dato se devuelve con su origen ('sistema' o 'manual'). Solo lectura.
     *
     * @param  array<string, mixed>  $datosManuales
     * @return array<string, mixed>
     */
    public function propuesta(Empresa $empresa, Colaborador $colaborador, array $datosManuales = []): array
    {
        $this->validarColaborador($empresa, $colaborador);
        $resultado = $this->calcular($empresa, $colaborador, $datosManuales);
        if (! $resultado['puede_calcular']) {
            return $resultado;
        }

        // Diferencia en soles de cada opción de las validaciones abiertas o
        // resueltas: se recalcula el escenario con esa opción elegida.
        foreach ($resultado['validaciones'] as &$validacion) {
            if (count($validacion['opciones']) < 2) {
                continue;
            }
            foreach ($validacion['opciones'] as $opcion) {
                try {
                    $escenario = $this->calcular($empresa, $colaborador, [...$datosManuales, $validacion['clave'] => $opcion['value']]);
                    $validacion['diferencias'][] = [
                        'opcion' => $opcion['value'], 'etiqueta' => $opcion['label'],
                        'total_ingresos' => $escenario['total_ingresos'], 'neto_pagar' => $escenario['neto_pagar'],
                        'diferencia_neto' => round($escenario['neto_pagar'] - $resultado['neto_pagar'], 2),
                    ];
                } catch (Throwable) {
                    // Una opción que no se puede calcular (p. ej. parámetros del
                    // régimen sin configurar) simplemente no muestra diferencia.
                }
            }
        }
        unset($validacion);

        return $resultado;
    }

    /** @return array<string, mixed> */
    private function calcular(Empresa $empresa, Colaborador $colaborador, array $manuales): array
    {
        $cese = Carbon::parse($colaborador->fecha_cese->toDateString());
        $decisiones = array_map(fn ($v) => $v === null ? null : (string) $v, array_intersect_key($manuales, array_flip(self::DECISIONES)));
        $ingreso = Carbon::parse(! empty($manuales['fecha_ingreso']) ? $manuales['fecha_ingreso'] : $colaborador->fecha_ingreso->toDateString());
        if ($ingreso->gt($cese)) {
            throw ValidationException::withMessages(['datos.fecha_ingreso' => 'La fecha de ingreso no puede ser posterior a la fecha de cese.']);
        }

        // --- Régimen por período y parámetros de cada tramo ---
        $regimen = $this->regimenes->resolver($empresa, $colaborador, $ingreso, $cese, $decisiones);
        $validaciones = $regimen['validaciones'];
        $alertas = $regimen['alertas'];
        $regimenCese = $regimen['regimen_al_cese'] ?: 'General';
        $parametros = $this->parametros($empresa, $regimenCese, $cese);
        $tramos = [];
        $versiones = [];
        foreach ($regimen['tramos'] as $tramo) {
            $regimenTramo = $tramo['regimen'] ?: 'General';
            try {
                $p = ParametrosVigentesResolver::paraRegimen($empresa, $regimenTramo, Carbon::parse($tramo['hasta'])->min($cese)->toDateString());
            } catch (RuntimeException) {
                // Tasas por régimen (gratificación, CTS, días de vacaciones)
                // sin vigencia histórica configurada: se usan las del cese.
                $p = $this->parametros($empresa, $regimenTramo, $cese);
                $alertas[] = "Parámetros de {$regimenTramo} sin vigencia al ".Carbon::parse($tramo['hasta'])->format('d/m/Y').': se usan los vigentes al cese.';
            }
            $versiones[$regimenTramo] = $p['version_id'] ?? null;
            $tramos[] = [
                ...$tramo, 'regimen' => $regimenTramo,
                'tasa_gratificacion' => $p['tasa_gratificacion'], 'tasa_bonificacion' => $p['tasa_bonificacion_extraordinaria'],
                'tasa_cts' => $p['tasa_cts'], 'vacaciones_dias' => $p['vacaciones_dias'],
            ];
        }
        if ($tramos === []) {
            throw ValidationException::withMessages(['colaborador' => 'El vínculo no tiene ningún período laboral que liquidar.']);
        }
        $ingresoLaboral = Carbon::parse($tramos[0]['desde']);

        // --- Variables (antes de los datos: deciden qué datos mostrar) ---
        $porMes = $this->variables->montosPorMes($colaborador, $cese->copy()->startOfMonth()->subMonths(12), $cese);
        $tieneComisiones = collect($porMes)->contains(fn ($mes) => ($mes['COMISION'] ?? 0) > 0);

        $datos = $this->resolverDatos($empresa, $colaborador, $cese, $parametros, $manuales, $tieneComisiones);
        $valor = fn (string $clave) => $datos[$clave]['valor'] ?? null;
        $alertas = [...$alertas, ...collect($datos)->filter(fn ($d) => $d['estado'] !== 'ok' && $d['origen'] === 'sistema' && $d['ayuda'])->pluck('ayuda')->values()->all()];

        $base = [
            'colaborador' => [
                'id' => $colaborador->id, 'nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'documento' => $colaborador->numero_documento, 'cargo' => $colaborador->cargo,
                'fecha_ingreso' => $colaborador->fecha_ingreso->toDateString(),
                'fecha_cese' => $cese->toDateString(), 'motivo_cese' => $colaborador->motivo_cese, 'causal_cese' => $colaborador->causal_cese,
            ],
            'regimen_laboral' => $regimenCese,
            'regimen_tramos' => $tramos,
            'parametros_version' => $versiones,
            'datos_calculo' => $datos,
        ];

        if (($decisiones['decision_clasificacion_vinculo'] ?? null) === 'locacion') {
            // Locación autónoma: no hay beneficios laborales que liquidar
            // (el honorario pendiente sale en el recibo del ciclo).
            $validaciones = collect($validaciones)->unique('clave')->values()->all();

            return [...$base, 'datos_calculo' => [...$datos, ...$this->datosDecision($validaciones, $manuales)],
                'puede_calcular' => true, 'remuneracion_computable' => 0.0, 'conceptos' => [], 'bases_variables' => [], 'bases' => [],
                'validaciones' => $validaciones, 'requiere_validacion' => collect($validaciones)->contains(fn ($v) => $v['bloqueante'] && ! $v['resuelta']),
                'total_ingresos' => 0.0, 'total_egresos' => 0.0, 'total_aportaciones' => 0.0, 'neto_pagar' => 0.0,
                'alertas' => [...$alertas, 'Clasificado como locación autónoma: no corresponde liquidación laboral. Registra el cierre del contrato civil fuera de Liquidaciones.']];
        }

        if ($valor('sueldo') === null) {
            return [...$base, 'puede_calcular' => false, 'remuneracion_computable' => null, 'conceptos' => [], 'bases_variables' => [],
                'validaciones' => $validaciones, 'requiere_validacion' => true,
                'total_ingresos' => 0.0, 'total_egresos' => 0.0, 'total_aportaciones' => 0.0, 'neto_pagar' => 0.0, 'alertas' => $alertas];
        }

        // --- Asignación familiar en microempresa (decisión pendiente) ---
        $af = (float) $valor('asignacion_familiar');
        if ($regimenCese === 'Micro Empresa' && $colaborador->tiene_hijos_asignacion_familiar && $parametros['tasa_asignacion_familiar'] <= 0) {
            $elegido = $decisiones['decision_af_microempresa'] ?? null;
            $validaciones[] = $this->validacion('decision_af_microempresa', 'Asignación familiar en microempresa',
                'El trabajador tiene hijos registrados y su régimen al cese es microempresa, configurada sin asignación familiar. ¿Corresponde incluirla (10 % de la RMV) en la base de los beneficios?',
                [['value' => 'incluir_af', 'label' => 'Sí, incluir asignación familiar'], ['value' => 'no_incluir_af', 'label' => 'No corresponde']],
                'Ley 25129 frente al régimen MYPE (pendiente de validación laboral)', ['Base de vacaciones', 'Indemnización'], $elegido);
            if ($elegido === 'incluir_af') {
                $af = round($parametros['rmv'] * 0.10, 2);
            }
        }

        // --- Bases por beneficio ---
        $sueldo = (float) $valor('sueldo');
        $sueldoMesAnterior = $this->sueldoVigente($colaborador, $cese->copy()->startOfMonth()->subDay()) ?? $sueldo;
        if ($datos['sueldo']['origen'] === 'manual') {
            $sueldoMesAnterior = $sueldo;
        }
        $comisionPrincipal = (bool) $valor('comision_principal');
        $inicioSemestreCts = match (true) {
            $cese->month >= 5 && $cese->month <= 10 => $cese->copy()->startOfMonth()->month(5),
            $cese->month >= 11 => $cese->copy()->startOfMonth()->month(11),
            default => $cese->copy()->startOfMonth()->subYear()->month(11),
        };
        $inicioSemestreGrat = $cese->month <= 6 ? $cese->copy()->startOfYear() : $cese->copy()->startOfMonth()->month(7);
        $finGrat = $cese->isLastOfMonth() ? $cese->copy() : $cese->copy()->startOfMonth()->subDay();

        $varCts = $this->variables->calcular($porMes, ['beneficio' => 'CTS trunca', 'afectacion' => 'afecta_cts',
            'desde' => $inicioSemestreCts->copy()->max($ingresoLaboral), 'hasta' => $cese, 'comision_principal' => $comisionPrincipal,
            'regla' => 'Período a liquidar del semestre CTS. Complementarias: 3 meses del período, suma/6; principal imprecisa: promedio diario si el período es menor a 6 meses (TUO CTS arts. 16-17).']);
        $varGrat = $finGrat->gte($inicioSemestreGrat->copy()->max($ingresoLaboral))
            ? $this->variables->calcular($porMes, ['beneficio' => 'Gratificación trunca', 'afectacion' => 'afecta_gratificacion',
                'desde' => $inicioSemestreGrat->copy()->max($ingresoLaboral), 'hasta' => $finGrat, 'comision_principal' => $comisionPrincipal,
                'regla' => 'Meses del semestre de gratificación anteriores al cese. Complementarias: 3 meses, suma/6 (Ley 27735 art. 3; D.S. 005-2002-TR num. 3.1).'])
            : null;
        $varVac = $this->variables->calcular($porMes, ['beneficio' => 'Vacaciones', 'afectacion' => 'afecta_vacaciones',
            'desde' => $cese->copy()->startOfMonth()->subMonthsNoOverflow(6)->max($ingresoLaboral), 'hasta' => $cese->copy()->startOfMonth()->subDay(), 'comision_principal' => $comisionPrincipal,
            'regla' => 'Seis meses calendario anteriores al mes del cese, con los criterios de la CTS por analogía (D.Leg. 713 art. 15; D.S. 012-92-TR arts. 16-17). Ventana pendiente de validación laboral.']);
        $basesVariables = array_values(array_filter([$varGrat, $varCts, $varVac]));

        $gratPercibida = (float) $valor('gratificacion_percibida_cts');
        $baseGrat = round($sueldoMesAnterior + $af + ($varGrat['total'] ?? 0), 2);
        $baseCts = round($sueldo + $af + $varCts['total'] + $gratPercibida / 6, 2);
        $baseVac = round($sueldo + $af + $varVac['total'], 2);

        // EPS: la bonificación extraordinaria es 6.75 % (D.S. 007-2009-TR art. 6).
        $cobertura = (string) $valor('cobertura_salud');
        if ($cobertura === 'eps') {
            $tramos = array_map(fn ($t) => $t['tasa_bonificacion'] > 0 ? [...$t, 'tasa_bonificacion' => 0.0675] : $t, $tramos);
        }

        // --- Gratificación del semestre cerrado no pagada (Ley 27735 art. 7) ---
        $gratAnterior = null;
        $gratJulioPagada = $this->beneficioPagado($colaborador, 'gratificacion_julio', $cese->year, $cese);
        if ($cese->month === 7 && $cese->day <= 15 && ! $gratJulioPagada && $ingresoLaboral->lt(Carbon::create($cese->year, 6, 1))) {
            $varAnterior = $this->variables->calcular($porMes, ['beneficio' => 'Gratificación del semestre cerrado', 'afectacion' => 'afecta_gratificacion',
                'desde' => Carbon::create($cese->year, 1, 1)->max($ingresoLaboral), 'hasta' => Carbon::create($cese->year, 6, 30), 'comision_principal' => $comisionPrincipal,
                'regla' => 'Semestre enero–junio. Complementarias: 3 meses, suma/6 (Ley 27735 art. 3).']);
            $basesVariables[] = $varAnterior;
            $gratAnterior = ['base' => round(($this->sueldoVigente($colaborador, Carbon::create($cese->year, 6, 30)) ?? $sueldo) + $af + $varAnterior['total'], 2),
                'inicio' => Carbon::create($cese->year, 1, 1), 'fin' => Carbon::create($cese->year, 6, 30)];
        } elseif ($cese->month > 7 || ($cese->month === 7 && $cese->day > 15)) {
            if (! $gratJulioPagada && $ingresoLaboral->lt(Carbon::create($cese->year, 6, 1))) {
                $alertas[] = 'No hay registro de pago de la gratificación de julio de este año. Si no se pagó, es una deuda ordinaria (Ley 27735 art. 6): regístrala o agrégala como otro ingreso.';
            }
        }

        // --- Faltas en el récord trunco (decisión pendiente) ---
        $anios = intdiv(DiasComerciales::entre($ingresoLaboral, $cese), 360);
        $inicioTrunco = $ingresoLaboral->copy()->addYearsNoOverflow($anios);
        $faltas = AsistenciaResultadoDiario::where('empresa_id', $empresa->id)->where('colaborador_id', $colaborador->id)
            ->where('estado', 'falta')->whereDate('fecha', '>=', $inicioTrunco->toDateString())->whereDate('fecha', '<=', $cese->toDateString())->count();
        $faltasADescontar = 0;
        if ($faltas > 0) {
            $elegido = $decisiones['decision_faltas_vacaciones'] ?? null;
            $validaciones[] = $this->validacion('decision_faltas_vacaciones', 'Faltas y vacaciones truncas',
                "El récord trunco ({$inicioTrunco->format('d/m/Y')}–{$cese->format('d/m/Y')}) tiene {$faltas} falta(s) registradas en asistencia. ¿Reducen los días computables de las vacaciones truncas?",
                [['value' => 'no_descontar', 'label' => 'No descontar'], ['value' => 'descontar_dias', 'label' => "Descontar {$faltas} día(s) del récord trunco"]],
                'D.Leg. 713 arts. 10 y 22; D.S. 012-92-TR art. 23 (dozavos y treintavos "computables"): qué ausencias computan queda pendiente de validación', ['Vacaciones truncas'], $elegido);
            if ($elegido === 'descontar_dias') {
                $faltasADescontar = $faltas;
            }
        }

        $entrada = [
            'fecha_ingreso' => $ingresoLaboral, 'fecha_cese' => $cese, 'tramos' => array_map(fn ($t) => [...$t, 'desde' => Carbon::parse($t['desde']), 'hasta' => Carbon::parse($t['hasta'])], $tramos),
            'base_gratificacion' => $baseGrat, 'base_cts' => $baseCts, 'base_vacaciones' => $baseVac,
            'gratificacion_semestre_pagada' => (bool) $valor('gratificacion_semestre_pagada'),
            'gratificacion_semestre_anterior' => $gratAnterior,
            // Ya viene neto del kardex (ver resolverDatos), así que no se suma ajuste aparte.
            'dias_vacaciones_gozados' => (float) $valor('dias_vacaciones_gozados'),
            'faltas_vacaciones_truncas' => $faltasADescontar,
            'excluir_indemnizacion_vacacional' => (bool) $valor('gerente_no_goza_vacaciones'),
        ];
        $calculo = CalculadoraBeneficiosCese::calcular($entrada);

        // --- Tope de CTS en pequeña empresa (decisión pendiente) ---
        if ($calculo['cts_pequena_rem_diarias'] > 0) {
            $acumulado = 0.0;
            foreach ($entrada['tramos'] as $t) {
                if ($t['regimen'] === 'Pequeña Empresa' && $t['desde']->lt($inicioSemestreCts)) {
                    $acumulado += DiasComerciales::entre($t['desde']->copy()->max($ingresoLaboral), $t['hasta']->copy()->min($inicioSemestreCts->copy()->subDay())) / 360 * 15;
                }
            }
            if ($acumulado + $calculo['cts_pequena_rem_diarias'] > 90) {
                $elegido = $decisiones['decision_tope_cts_pequena'] ?? null;
                $validaciones[] = $this->validacion('decision_tope_cts_pequena', 'Tope de CTS en pequeña empresa',
                    'Con 15 remuneraciones diarias por año, el vínculo acumularía '.round($acumulado, 2).' antes de este semestre (tope 90). Agento no tiene el historial de depósitos para reconstruir el acumulado real. ¿Se aplica el tope?',
                    [['value' => 'aplicar_tope_teorico', 'label' => 'Aplicar el tope con el acumulado teórico ('.round($acumulado, 2).' rem. diarias)'], ['value' => 'no_aplicar_tope', 'label' => 'No aplicar el tope']],
                    'TUO Ley MYPE art. 50; Ley 32353 art. 44.3 e). Forma de reconstruir el acumulado: pendiente de validación', ['CTS trunca'], $elegido);
                if ($elegido === 'aplicar_tope_teorico') {
                    $calculo = CalculadoraBeneficiosCese::calcular([...$entrada, 'cts_acumulado_rem_diarias_pequena' => min(90.0, $acumulado)]);
                }
            }
        }
        $conceptos = $calculo['conceptos'];
        $alertas = [...$alertas, ...$calculo['alertas']];

        // --- CTS de un semestre cerrado aún no depositada (decisión pendiente) ---
        if ($semestre = $this->semestreCtsCerrado($cese)) {
            [$tipo, $anio, $inicio, $fin, $vence] = $semestre;
            $diasSemestre = DiasComerciales::entre($inicio->copy()->max($ingresoLaboral), $fin);
            $depositado = $this->beneficioPagado($colaborador, $tipo, $anio, null);
            if (! $depositado && $ingresoLaboral->lte($fin) && $diasSemestre >= 30) {
                if ($cese->lte($vence)) {
                    $varCerrado = $this->variables->calcular($porMes, ['beneficio' => 'CTS semestre cerrado', 'afectacion' => 'afecta_cts',
                        'desde' => $inicio->copy()->max($ingresoLaboral), 'hasta' => $fin, 'comision_principal' => $comisionPrincipal,
                        'regla' => 'Semestre CTS cerrado (TUO CTS arts. 16-18 y 21).']);
                    $basesVariables[] = $varCerrado;
                    $gratSemestre = $this->gratificacionPercibidaEn($colaborador, $tipo === 'cts_noviembre' ? 'gratificacion_julio' : 'gratificacion_diciembre', $tipo === 'cts_noviembre' ? $anio : $anio - 1, $fin);
                    $baseCerrado = round(($this->sueldoVigente($colaborador, $fin) ?? $sueldo) + $af + $varCerrado['total'] + $gratSemestre / 6, 2);
                    $monto = 0.0;
                    $detalle = [];
                    foreach (CalculadoraBeneficiosCese::diasPorTramo($inicio->copy()->max($ingresoLaboral), $fin, $entrada['tramos']) as [$t, $dias]) {
                        $monto += $baseCerrado / 360 * $dias * $t['tasa_cts'];
                        $detalle[] = "{$dias} d × {$t['tasa_cts']} ({$t['regimen']})";
                    }
                    $monto = round($monto, 2);
                    $periodo = $inicio->format('m/Y').'–'.$fin->format('m/Y');
                    $elegido = $decisiones['decision_cts_semestre_cerrado'] ?? null;
                    $validaciones[] = $this->validacion('decision_cts_semestre_cerrado', 'CTS del semestre cerrado sin depósito',
                        "El semestre CTS {$periodo} terminó antes del cese y no figura su depósito (vence el {$vence->format('d/m/Y')}). Su monto es S/ ".number_format($monto, 2).'. ¿Cómo se cancela? Si el depósito vence, corresponden intereses (TUO CTS art. 56), que Agento no calcula.',
                        [['value' => 'pago_directo', 'label' => 'Pagarla directamente en esta liquidación'], ['value' => 'deposito_en_entidad', 'label' => 'Depositarla en la entidad elegida (no va en el neto)']],
                        'TUO CTS arts. 3 (pago directo solo por período menor a un semestre), 21-22 y 56. Tratamiento al cese: pendiente de validación', ['CTS del semestre cerrado'], $elegido);
                    if ($elegido === 'pago_directo') {
                        $conceptos[] = [
                            'codigo' => 'CTS_SEMESTRE_CERRADO', 'nombre' => CatalogoConceptosLiquidacion::nombre('CTS_SEMESTRE_CERRADO'), 'tipo' => 'ingreso',
                            'monto' => $monto, 'base_utilizada' => $baseCerrado, 'cantidad' => $diasSemestre, 'tasa_aplicada' => null,
                            'formula_texto' => "({$baseCerrado} / 360) × [".implode(' + ', $detalle)."] — semestre {$periodo} sin depósito; intereses (TUO CTS art. 56) no incluidos",
                        ];
                    } elseif ($elegido === 'deposito_en_entidad') {
                        $alertas[] = "CTS del semestre {$periodo} (S/ ".number_format($monto, 2).') a depositar en la entidad elegida: no forma parte del neto de esta liquidación.';
                    }
                } else {
                    $alertas[] = 'No hay registro del depósito de CTS del semestre '.$inicio->format('m/Y').'–'.$fin->format('m/Y').' (venció el '.$vence->format('d/m/Y').'). Verifica que se depositó; si no, corresponde depositarlo con intereses (TUO CTS art. 56).';
                }
            }
        }

        // --- Indemnización por despido arbitrario ---
        $causal = $valor('causal_cese');
        $plazoFijoMype = null;
        $finContrato = $colaborador->fecha_fin_contrato ? Carbon::parse($colaborador->fecha_fin_contrato->toDateString()) : null;
        if ($causal === 'despido_arbitrario' && in_array($regimenCese, ResolverRegimenLiquidacion::MYPE, true) && $colaborador->tipo_contrato === 'plazo_fijo' && $finContrato?->gt($cese)) {
            $plazoFijoMype = $decisiones['decision_indemnizacion_plazo_fijo_mype'] ?? null;
            $validaciones[] = $this->validacion('decision_indemnizacion_plazo_fijo_mype', 'Indemnización: contrato a plazo fijo en régimen MYPE',
                "El contrato vencía el {$finContrato->format('d/m/Y')}. ¿Qué fórmula de indemnización aplica?",
                [['value' => 'formula_mype', 'label' => 'Régimen MYPE: remuneraciones diarias por año (TUO Ley MYPE art. 56)'], ['value' => 'art_76', 'label' => '1.5 remuneraciones por mes faltante (TUO D.Leg. 728 art. 76)']],
                'TUO Ley MYPE art. 56 frente a TUO D.Leg. 728 art. 76', ['Indemnización por despido arbitrario'], $plazoFijoMype);
        }
        if ($causal === 'despido_arbitrario' && collect($tramos)->pluck('regimen')->unique()->count() > 1) {
            $alertas[] = 'El vínculo tuvo más de un régimen: la indemnización se calcula con el régimen al cese ('.$regimenCese.'). Revisa si corresponde otro criterio.';
        }
        $despido = CalculadoraIndemnizacionDespido::calcular([
            'regimen' => $regimenCese, 'causal' => $causal, 'tipo_contrato' => $colaborador->tipo_contrato,
            'fecha_ingreso' => $ingresoLaboral, 'fecha_cese' => $cese, 'fecha_fin_contrato' => $finContrato,
            'remuneracion_mensual' => round($sueldo + $af, 2), 'periodo_prueba_meses' => (float) ($valor('periodo_prueba_meses') ?? 3),
            'plazo_fijo_mype' => $plazoFijoMype,
        ]);
        if ($despido['linea']) {
            $conceptos[] = $despido['linea'];
        }
        $alertas = [...$alertas, ...$despido['alertas']];
        if ($causal === null) {
            $alertas[] = 'Causal de cese sin tipificar: la indemnización por despido no se calcula. Si fue despido arbitrario, selecciona la causal en los datos del cálculo.';
        }

        // --- Retenciones y aportes ---
        $conceptos = [...$conceptos, ...$this->aportesSobreVacaciones($empresa, $colaborador, $regimenCese, $parametros, $cese, $conceptos, $alertas)];
        if ($essalud = $this->aporteSalud($cobertura, $parametros, $conceptos, $alertas)) {
            $conceptos[] = $essalud;
        }
        $quinta = null;
        if ($renta = $this->rentaQuinta($colaborador, $parametros, $conceptos, $datos, $alertas, $validaciones, $decisiones['decision_quinta_boleta_cese'] ?? null, $quinta)) {
            $conceptos[] = $renta;
        }

        $validaciones = collect($validaciones)->unique('clave')->values()->all();
        $datos = [...$datos, ...$this->datosDecision($validaciones, $manuales)];

        return [
            ...$base,
            'datos_calculo' => $datos,
            'puede_calcular' => true,
            'remuneracion_computable' => $calculo['remuneracion_computable'],
            'bases' => ['gratificacion' => $baseGrat, 'cts' => $baseCts, 'vacaciones' => $baseVac, 'sueldo_mes_anterior' => $sueldoMesAnterior, 'asignacion_familiar' => $af],
            'bases_variables' => $basesVariables,
            'conceptos' => $conceptos,
            ...$this->totales($conceptos),
            'validaciones' => $validaciones,
            'requiere_validacion' => collect($validaciones)->contains(fn ($v) => $v['bloqueante'] && ! $v['resuelta']),
            'quinta_cese' => $quinta,
            'alertas' => array_values(array_unique($alertas)),
        ];
    }

    /**
     * Datos de entrada del cálculo: valor del sistema, o el ingresado por
     * RR.HH. cuando falta o no es correcto. estado: 'ok', 'faltante' (impide
     * calcular) o 'revisar' (el sistema puede no tener el historial completo).
     *
     * @return array<string, array<string, mixed>>
     */
    private function resolverDatos(Empresa $empresa, Colaborador $colaborador, Carbon $cese, array $parametros, array $manuales, bool $tieneComisiones): array
    {
        $remuneracion = $this->remuneracionAlCese($colaborador, $cese);

        $tipoGratActual = $cese->month <= 6 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $gratSemestrePagada = $this->beneficioPagado($colaborador, $tipoGratActual, $cese->year, null);

        $tipoGratCts = $cese->month >= 5 && $cese->month <= 10 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $anioGratCts = $cese->month <= 4 ? $cese->year - 1 : $cese->year;
        $gratPercibida = $this->gratificacionPercibidaEn($colaborador, $tipoGratCts, $anioGratCts, $cese, true);

        $tomadas = (float) $this->asistencia->vacacionesTomadas($colaborador, $colaborador->fecha_ingreso->toDateString(), $cese->toDateString())['dias'];
        $kardex = VacacionMovimiento::where('colaborador_id', $colaborador->id)->whereDate('fecha', '<=', $cese);
        $tieneKardex = (clone $kardex)->exists();
        // Gozados netos: permisos de vacaciones aprobados menos los ajustes del
        // kardex (un ajuste positivo suma saldo, es decir, resta gozados).
        $gozadosNetos = round($tomadas - (float) $kardex->sum('dias'), 4);
        $diasServicio = DiasComerciales::entre(Carbon::parse($colaborador->fecha_ingreso->toDateString()), $cese);

        $sistema = [
            'fecha_ingreso' => ['Fecha de ingreso', 'fecha', $colaborador->fecha_ingreso->toDateString(), 'ok',
                'Si el colaborador ingresó antes de su registro en el sistema, ingresa la fecha real.'],
            'sueldo' => ['Sueldo básico al cese', 'monto', $remuneracion ? round((float) $remuneracion->salario, 2) : null,
                $remuneracion ? 'ok' : 'faltante', 'No hay una remuneración registrada a la fecha de cese: ingresa el sueldo básico.'],
            'asignacion_familiar' => ['Asignación familiar', 'monto',
                $colaborador->tiene_hijos_asignacion_familiar && $parametros['tasa_asignacion_familiar'] > 0 ? round((float) $parametros['asignacion_familiar_monto'], 2) : 0.0,
                'ok', null],
            'gratificacion_percibida_cts' => ['Gratificación percibida en el semestre de CTS', 'monto', round($gratPercibida ?? 0, 2),
                $gratPercibida !== null ? 'ok' : 'revisar', 'No hay una gratificación pagada registrada en el semestre de CTS (su sexto forma parte de la base de CTS, TUO CTS art. 18). Si se pagó fuera del sistema, ingrésala.'],
            'gratificacion_semestre_pagada' => ['Gratificación del semestre del cese ya pagada', 'si_no', $gratSemestrePagada, 'ok',
                'Si ya se pagó la gratificación de este semestre, la gratificación trunca va en 0.'],
            'dias_vacaciones_gozados' => ['Días de vacaciones ya gozados (en todo el vínculo)', 'dias', $gozadosNetos,
                $tieneKardex ? 'ok' : 'revisar', 'Días gozados según permisos de vacaciones aprobados; sin ajustes en el kardex vacacional. Si gozó vacaciones antes de usar el sistema, ingresa el total real.'],
            'causal_cese' => ['Causal del cese', 'opcion', $colaborador->causal_cese, $colaborador->causal_cese ? 'ok' : 'revisar',
                'Causal sin tipificar (cese registrado con texto libre: "'.$colaborador->motivo_cese.'"). Selecciónala: la indemnización por despido solo se calcula con "Despido arbitrario".',
                collect(Colaborador::CAUSALES_CESE)->map(fn ($etiqueta, $valor) => ['value' => $valor, 'label' => $etiqueta])->values()->all()],
            'cobertura_salud' => ['Cobertura de salud del trabajador', 'opcion', $empresa->seguro_salud ?: 'essalud', 'ok', null, [
                ['value' => 'essalud', 'label' => 'EsSalud'], ['value' => 'eps', 'label' => 'EsSalud + EPS'], ['value' => 'sis', 'label' => 'SIS (microempresa)'],
            ]],
        ];
        $causalManual = $manuales['causal_cese'] ?? null;
        if (($causalManual ?? $colaborador->causal_cese) === 'despido_arbitrario') {
            $sistema['periodo_prueba_meses'] = ['Período de prueba pactado (meses)', 'numero', 3.0, 'ok', 'TUO D.Leg. 728 art. 10: 3 meses, ampliable por escrito hasta 6 (calificados o de confianza) o 12 (dirección).'];
        }
        $sistema['remuneraciones_otros_empleadores'] = ['Remuneraciones gravadas de otros empleadores en el año (certificado)', 'monto', 0.0, 'ok',
            'Ajuste de 5ta al cese: suma las remuneraciones de otros empleadores acreditadas con su certificado (Reglamento LIR art. 41 incs. c y d).'];
        $sistema['retenciones_otros_empleadores'] = ['Retenciones de 5ta de otros empleadores en el año', 'monto', 0.0, 'ok', null];
        if ($tieneComisiones) {
            $sistema['comision_principal'] = ['La comisión es su remuneración principal (comisionista)', 'si_no', false, 'ok',
                'Sí: promedio de lo percibido (diario si el período es menor a 6 meses). No: complementaria, solo si se percibió 3 de 6 meses (TUO CTS arts. 16-17).'];
        }
        if ($diasServicio >= 720) {
            $sistema['gerente_no_goza_vacaciones'] = ['Gerente o representante que decidió no gozar vacaciones', 'si_no', false, 'ok',
                'Si es gerente o representante y decidió no gozar el descanso, no corresponde indemnización vacacional (D.S. 012-92-TR art. 24).'];
        }

        $datos = [];
        foreach ($sistema as $clave => $definicion) {
            [$etiqueta, $tipo, $valorSistema, $estado, $ayuda] = $definicion;
            $opciones = $definicion[5] ?? null;
            $manual = array_key_exists($clave, $manuales) && $manuales[$clave] !== null && $manuales[$clave] !== '' ? $manuales[$clave] : null;
            $valor = $valorSistema;
            $origen = 'sistema';
            if ($manual !== null) {
                $normalizado = match ($tipo) {
                    'fecha' => Carbon::parse($manual)->toDateString(),
                    'si_no' => filter_var($manual, FILTER_VALIDATE_BOOLEAN),
                    'opcion' => (string) $manual,
                    default => round((float) $manual, $tipo === 'dias' ? 4 : 2),
                };
                if ($tipo === 'opcion' && ! in_array($normalizado, array_column($opciones, 'value'), true)) {
                    throw ValidationException::withMessages(["datos.{$clave}" => "Valor no válido para {$etiqueta}."]);
                }
                if ($normalizado !== $valorSistema) {
                    $valor = $normalizado;
                    $origen = 'manual';
                    $estado = 'ok';
                }
            }
            $datos[$clave] = [
                'etiqueta' => $etiqueta, 'tipo' => $tipo, 'valor' => $valor, 'valor_sistema' => $valorSistema,
                'origen' => $origen, 'estado' => $estado, 'ayuda' => $ayuda, ...($opciones ? ['opciones' => $opciones] : []),
            ];
        }

        return $datos;
    }

    /**
     * Las decisiones de las validaciones se guardan como datos del cálculo:
     * quedan en el snapshot de la liquidación y, al ser manuales, exigen
     * sustento.
     */
    private function datosDecision(array $validaciones, array $manuales): array
    {
        $datos = [];
        foreach ($validaciones as $v) {
            $decidido = $v['resuelta'] ? $v['decision'] : null;
            $datos[$v['clave']] = [
                'etiqueta' => $v['titulo'], 'tipo' => 'opcion', 'valor' => $decidido, 'valor_sistema' => null,
                'origen' => $decidido !== null ? 'manual' : 'sistema', 'estado' => $decidido !== null ? 'ok' : 'faltante',
                'ayuda' => $v['pregunta'], 'opciones' => $v['opciones'], 'es_decision' => true,
            ];
        }

        return $datos;
    }

    /**
     * @param  array<int, array{codigo: string, monto: float|int|string, nombre?: ?string, motivo_ajuste?: ?string}>  $lineas
     * @param  array<string, mixed>  $datosManuales  Datos de entrada completados por RR.HH. (solo modo asistido).
     */
    public function registrar(Empresa $empresa, Colaborador $colaborador, string $modo, array $lineas, ?string $sustento, int $usuarioId, array $datosManuales = []): LiquidacionCese
    {
        $this->validarColaborador($empresa, $colaborador);
        if (! in_array($modo, [LiquidacionCese::MODO_ASISTIDO, LiquidacionCese::MODO_MANUAL], true)) {
            throw ValidationException::withMessages(['modo' => 'Modo de liquidación inválido.']);
        }
        if ($modo === LiquidacionCese::MODO_MANUAL && mb_strlen(trim((string) $sustento)) < 5) {
            throw ValidationException::withMessages(['sustento' => 'La liquidación manual requiere el sustento del cálculo (de dónde salen los montos).']);
        }

        // Todo dentro de la transacción y con el colaborador bloqueado (mismo
        // bloqueo que la boleta del mes del cese): la propuesta se calcula con
        // lo que ya esté registrado, sin intercalarse con un cálculo paralelo.
        return DB::transaction(function () use ($empresa, $colaborador, $modo, $lineas, $sustento, $usuarioId, $datosManuales) {
            Colaborador::whereKey($colaborador->id)->lockForUpdate()->firstOrFail();

            // Misma función que la vista previa: lo emitido coincide con lo mostrado.
            $propuesta = $modo === LiquidacionCese::MODO_ASISTIDO ? $this->propuesta($empresa, $colaborador, $datosManuales) : null;
            if ($propuesta && ! $propuesta['puede_calcular']) {
                throw ValidationException::withMessages(['datos' => 'Faltan datos para calcular la liquidación: completa el sueldo básico al cese.']);
            }
            $usaDatosManuales = $propuesta && collect($propuesta['datos_calculo'])->contains('origen', 'manual');
            if ($usaDatosManuales && mb_strlen(trim((string) $sustento)) < 5) {
                throw ValidationException::withMessages(['sustento' => 'Indica el sustento de los datos y decisiones ingresados manualmente (de dónde salen).']);
            }
            $conceptos = $this->resolverLineas($lineas, $propuesta);
            $totales = $this->totales($conceptos);
            if ($totales['total_ingresos'] <= 0) {
                throw ValidationException::withMessages(['conceptos' => 'La liquidación debe tener al menos un ingreso mayor a cero.']);
            }
            if ($totales['neto_pagar'] < 0) {
                throw ValidationException::withMessages(['conceptos' => 'Los descuentos superan a los ingresos.']);
            }

            $vigente = LiquidacionCese::where('colaborador_id', $colaborador->id)->where('es_version_vigente', true)
                ->where('estado', '!=', 'anulada')->lockForUpdate()->first();
            if ($vigente && $vigente->estado !== 'calculada') {
                throw ValidationException::withMessages(['liquidacion' => 'Ya existe una liquidación aprobada o pagada para este colaborador. Anúlala antes de registrar otra.']);
            }
            // Una calculada se reemplaza por la nueva versión (queda en historial).
            $vigente?->update(['es_version_vigente' => false]);

            $remuneracion = $this->remuneracionAlCese($colaborador, Carbon::parse($colaborador->fecha_cese->toDateString()));
            $liquidacion = LiquidacionCese::create([
                'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
                'fecha_cese' => $colaborador->fecha_cese->toDateString(), 'motivo_cese' => (string) $colaborador->motivo_cese,
                'causal_cese' => $propuesta['datos_calculo']['causal_cese']['valor'] ?? $colaborador->causal_cese,
                'remuneracion_snapshot' => round((float) ($propuesta['datos_calculo']['sueldo']['valor'] ?? $remuneracion?->salario ?? 0), 2),
                'regimen_laboral_snapshot' => $propuesta['regimen_laboral'] ?? ($colaborador->regimen_laboral ?: 'General'),
                'regimen_tramos' => $propuesta['regimen_tramos'] ?? null,
                'modo' => $modo, 'sustento' => $sustento ? trim($sustento) : null,
                'datos_calculo' => $propuesta['datos_calculo'] ?? null,
                'validaciones' => $propuesta['validaciones'] ?? null,
                'bases_variables' => $propuesta ? ['bases' => $propuesta['bases'], 'variables' => $propuesta['bases_variables']] : null,
                'parametros_version' => $propuesta ? mb_substr(collect($propuesta['parametros_version'])->map(fn ($v, $r) => "{$r}:{$v}")->implode('|'), 0, 80) : null,
                'incluir_remuneracion' => false,
                ...$totales,
                'alertas' => $propuesta['alertas'] ?? [],
                'estado' => 'calculada',
                'version' => (int) LiquidacionCese::where('colaborador_id', $colaborador->id)->max('version') + 1,
                'es_version_vigente' => true,
                'calculado_por' => $usuarioId, 'calculado_at' => now(),
            ]);
            foreach ($conceptos as $concepto) {
                $liquidacion->conceptos()->create($concepto);
            }

            // Aplicación de 5ta del cese: el monto es el de las líneas guardadas
            // (retención − devolución), con el desglose del ajuste.
            if ($quinta = $propuesta['quinta_cese'] ?? null) {
                $monto = round(collect($conceptos)->where('codigo', 'RENTA_5TA')->sum('monto') - collect($conceptos)->where('codigo', 'DEVOLUCION_RENTA_5TA')->sum('monto'), 2);
                $this->quinta->registrarAplicacion($colaborador, QuintaAjusteCese::LIQUIDACION, $liquidacion->id, $liquidacion->version, $monto,
                    [...$quinta['desglose'], 'difiere' => $quinta['difiere']], 'borrador', $quinta['asignado'], $quinta['otros_gravado'], $quinta['otros_retenido']);
            }

            return $liquidacion->load('conceptos');
        });
    }

    /**
     * Estado de la aplicación de 5ta de una liquidación: emitida al aprobar o
     * pagar; al anularla deja de contar y la boleta del cese conciliará sin ella.
     */
    public function actualizarEstadoQuinta(LiquidacionCese $liquidacion, string $estado): void
    {
        $colaborador = $liquidacion->colaborador()->first();
        if ($colaborador?->fecha_cese) {
            $this->quinta->actualizarEstado($colaborador, QuintaAjusteCese::LIQUIDACION, $liquidacion->id, $estado);
        }
    }

    /**
     * Validaciones bloqueantes sin decisión de una liquidación emitida: con
     * alguna abierta no se puede aprobar. Las liquidaciones anteriores a la
     * validación por régimen no tienen validaciones y no se bloquean.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validacionesPendientes(LiquidacionCese $liquidacion): array
    {
        return collect($liquidacion->validaciones ?? [])->filter(fn ($v) => ($v['bloqueante'] ?? false) && ! ($v['resuelta'] ?? false))->values()->all();
    }

    /**
     * Cruza lo que envía RR.HH. con la propuesta: igual a la propuesta =
     * calculado; distinto = ajustado (exige motivo y guarda el calculado);
     * fuera de la propuesta o en modo manual = manual. El tipo siempre sale
     * del catálogo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolverLineas(array $lineas, ?array $propuesta): array
    {
        $propuestas = collect($propuesta['conceptos'] ?? [])->keyBy('codigo');
        $recibidas = collect($lineas);

        $quinta = ['RENTA_5TA', 'DEVOLUCION_RENTA_5TA'];
        $cambioQuinta = 'La renta de 5ta del cese cambió desde la vista previa (por ejemplo, se calculó la boleta del mes del cese): vuelve a calcular la liquidación.';
        foreach ($propuestas->keys() as $codigo) {
            if (! $recibidas->contains('codigo', $codigo)) {
                throw ValidationException::withMessages(['conceptos' => in_array($codigo, $quinta, true) ? $cambioQuinta : "Falta el concepto propuesto {$codigo}: para excluirlo, déjalo en 0 con el motivo."]);
            }
        }

        $resultado = [];
        $usadas = [];
        foreach ($recibidas as $indice => $linea) {
            $codigo = (string) ($linea['codigo'] ?? '');
            if (! CatalogoConceptosLiquidacion::existe($codigo)) {
                throw ValidationException::withMessages(["conceptos.{$indice}.codigo" => "Concepto {$codigo} no válido para una liquidación."]);
            }
            $monto = round((float) ($linea['monto'] ?? 0), 2);
            if ($monto < 0) {
                throw ValidationException::withMessages(["conceptos.{$indice}.monto" => 'Los montos no pueden ser negativos.']);
            }
            $motivo = trim((string) ($linea['motivo_ajuste'] ?? '')) ?: null;
            $nombreLibre = in_array($codigo, CatalogoConceptosLiquidacion::CON_NOMBRE_LIBRE, true) ? trim((string) ($linea['nombre'] ?? '')) : '';
            $nombre = $nombreLibre !== '' ? mb_substr($nombreLibre, 0, 160) : CatalogoConceptosLiquidacion::nombre($codigo);
            $base = ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => CatalogoConceptosLiquidacion::tipo($codigo), 'monto' => $monto];

            $calculado = ! isset($usadas[$codigo]) ? $propuestas->get($codigo) : null;
            if ($calculado) {
                $usadas[$codigo] = true;
                $igual = abs($monto - (float) $calculado['monto']) < 0.005;
                if (! $igual && ! $motivo && in_array($codigo, $quinta, true)) {
                    throw ValidationException::withMessages(["conceptos.{$indice}.monto" => $cambioQuinta]);
                }
                if (! $igual && ! $motivo) {
                    throw ValidationException::withMessages(["conceptos.{$indice}.motivo_ajuste" => "Indica el motivo del ajuste de {$calculado['nombre']}."]);
                }
                if ($monto <= 0 && $igual) {
                    continue; // propuesta en 0 sin cambios: no aporta nada al documento
                }
                $resultado[] = [
                    ...$base,
                    'nombre' => $calculado['nombre'],
                    'origen' => $igual ? LiquidacionCeseConcepto::ORIGEN_CALCULADO : LiquidacionCeseConcepto::ORIGEN_AJUSTADO,
                    'monto_calculado' => $calculado['monto'],
                    'base_utilizada' => $calculado['base_utilizada'], 'cantidad' => $calculado['cantidad'],
                    'tasa_aplicada' => $calculado['tasa_aplicada'],
                    'formula_texto' => $calculado['formula_texto'].($igual ? '' : ' — ajustado por RR.HH.'),
                    'motivo_ajuste' => $igual ? null : $motivo,
                ];

                continue;
            }

            if ($monto <= 0) {
                continue;
            }
            if ($propuesta && ! $motivo && in_array($codigo, $quinta, true)) {
                throw ValidationException::withMessages(["conceptos.{$indice}.codigo" => $cambioQuinta]);
            }
            if ($propuesta && ! $motivo) {
                throw ValidationException::withMessages(["conceptos.{$indice}.motivo_ajuste" => "Indica el sustento del concepto agregado {$nombre}."]);
            }
            $resultado[] = [
                ...$base, 'origen' => LiquidacionCeseConcepto::ORIGEN_MANUAL, 'monto_calculado' => null,
                'base_utilizada' => null, 'cantidad' => null, 'tasa_aplicada' => null,
                'formula_texto' => 'Ingresado por RR.HH.', 'motivo_ajuste' => $motivo,
            ];
        }

        return $resultado;
    }

    /**
     * AFP/ONP sobre lo remunerativo de la liquidación (vacaciones truncas y no
     * gozadas). Gratificación y bonificación (Ley 30334 art. 1), CTS e
     * indemnizaciones (D.Leg. 713 art. 23; TUO LIR art. 18) no están afectas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aportesSobreVacaciones(Empresa $empresa, Colaborador $colaborador, string $regimen, array $parametros, Carbon $cese, array $conceptos, array &$alertas): array
    {
        $base = round(collect($conceptos)
            ->filter(fn ($c) => CatalogoConceptosLiquidacion::afecto($c['codigo'], 'afecto_aportes'))
            ->sum('monto'), 2);
        if ($base <= 0) {
            return [];
        }

        try {
            $rma = $colaborador->sistema_previsional === 'onp' ? null : ParametrosVigentesResolver::rmaAfp($empresa, $regimen, $cese->toDateString());
            $lineas = RegimenCalculatorFactory::paraRegimen($regimen)->calcularAporteAfpOnp($colaborador, $base, $parametros, $cese->toDateString(), $rma);
        } catch (Throwable $e) {
            $alertas[] = 'No se pudo calcular AFP/ONP sobre las vacaciones: '.$e->getMessage().' Agrégalo manualmente.';

            return [];
        }

        return collect($lineas)
            ->filter(fn ($l) => CatalogoConceptosLiquidacion::existe($l['codigo']) && (float) $l['monto'] > 0)
            ->map(fn ($l) => [
                'codigo' => $l['codigo'], 'nombre' => CatalogoConceptosLiquidacion::nombre($l['codigo']), 'tipo' => 'egreso',
                'monto' => round((float) $l['monto'], 2), 'base_utilizada' => round((float) ($l['base_utilizada'] ?? $base), 2),
                'cantidad' => null, 'tasa_aplicada' => $l['tasa_aplicada'] ?? null,
                'formula_texto' => ($l['formula_texto'] ?? $l['codigo']).' — sobre vacaciones (truncas y no gozadas)',
            ])->values()->all();
    }

    /**
     * Aporte del empleador a EsSalud sobre lo remunerativo (Ley 26790 art. 6:
     * 9 % de la remuneración). Con EPS, crédito del 25 % (arts. 15-16): queda
     * 6.75 %. SIS: aporte mensual fijo, no se calcula por concepto.
     */
    private function aporteSalud(string $cobertura, array $parametros, array $conceptos, array &$alertas): ?array
    {
        $base = round(collect($conceptos)->filter(fn ($c) => CatalogoConceptosLiquidacion::afecto($c['codigo'], 'afecto_essalud'))->sum('monto'), 2);
        if ($base <= 0) {
            return null;
        }
        if ($cobertura === 'sis') {
            $alertas[] = 'SIS: el aporte es un monto mensual fijo; no se calcula sobre los conceptos de la liquidación.';

            return null;
        }
        $tasa = $parametros['tasa_essalud'] * ($cobertura === 'eps' ? 0.75 : 1);

        return [
            'codigo' => 'ESSALUD', 'nombre' => CatalogoConceptosLiquidacion::nombre('ESSALUD'), 'tipo' => 'aportacion',
            'monto' => round($base * $tasa, 2), 'base_utilizada' => $base, 'cantidad' => null, 'tasa_aplicada' => round($tasa, 6),
            'formula_texto' => "{$base} × ".round($tasa * 100, 2).' % sobre vacaciones (Ley 26790 art. 6'.($cobertura === 'eps' ? '; crédito EPS 25 %, arts. 15-16' : '').') — costo del empleador, no se descuenta al trabajador',
        ];
    }

    /**
     * Quinta del cese con el ajuste final único (AjusteFinalQuintaCese),
     * compartido con la boleta del mes del cese. Sin boleta del cese, RR.HH.
     * decide qué documento aplica el ajuste; con boleta ya calculada o
     * emitida, la liquidación concilia contra lo que esa boleta retuvo.
     */
    private function rentaQuinta(Colaborador $colaborador, array $parametros, array $conceptos, array $datos, array &$alertas, array &$validaciones, ?string $decision, ?array &$quinta): ?array
    {
        $quinta = null;
        if (empty($parametros['tramos_renta_5ta'])) {
            return null;
        }
        $gravado = round(collect($conceptos)->filter(fn ($c) => CatalogoConceptosLiquidacion::afecto($c['codigo'], 'afecto_5ta'))->sum('monto'), 2);
        $asignado = match ($decision) {
            'ajuste_en_liquidacion' => QuintaAjusteCese::LIQUIDACION,
            'ajuste_en_boleta_del_cese' => QuintaAjusteCese::BOLETA,
            default => null,
        };
        $opciones = [
            'otros_gravado' => (float) ($datos['remuneraciones_otros_empleadores']['valor'] ?? 0),
            'otros_retenido' => (float) ($datos['retenciones_otros_empleadores']['valor'] ?? 0),
        ];
        $hayBoleta = (bool) $this->quinta->boletaDelCese($colaborador);
        $registro = $this->quinta->registro($colaborador);
        // Sin boleta del cese se calcula como documento que aplica el ajuste
        // (opción por defecto mientras RR.HH. no decide); con boleta, rige el
        // registro o, si no hay, la boleta, y la liquidación concilia.
        $calculo = $this->quinta->calcular($colaborador, QuintaAjusteCese::LIQUIDACION, $gravado, $parametros,
            [...$opciones, 'asignado' => $hayBoleta ? null : QuintaAjusteCese::LIQUIDACION]);
        if (abs($calculo['ajuste']) < 0.01 && ! $registro) {
            return null;
        }

        if (! $hayBoleta) {
            $validaciones[] = $this->validacion('decision_quinta_boleta_cese', 'Quinta categoría: ajuste final del mes del cese',
                'La boleta del mes del cese todavía no está calculada. El ajuste final de 5ta hoy es una '.($calculo['ajuste'] >= 0 ? 'retención' : 'devolución').' de S/ '.number_format(abs($calculo['ajuste']), 2)
                .'. ¿Qué documento lo aplica? El otro no retendrá la proyección mensual: solo conciliará la diferencia por sus propios ingresos.',
                [['value' => 'ajuste_en_liquidacion', 'label' => 'Esta liquidación (la boleta del cese conciliará después)'], ['value' => 'ajuste_en_boleta_del_cese', 'label' => 'La boleta del mes del cese (aquí no hay línea de 5ta)']],
                'Reglamento LIR art. 41 inc. d: el ajuste final corresponde al mes del cese', ['Renta de 5ta categoría'], $decision);
            if ($asignado === QuintaAjusteCese::BOLETA) {
                $calculo = $this->quinta->calcular($colaborador, QuintaAjusteCese::LIQUIDACION, $gravado, $parametros, [...$opciones, 'asignado' => QuintaAjusteCese::BOLETA]);
            }
        }
        if ($calculo['mensaje']) {
            $alertas[] = 'Renta de 5ta: '.$calculo['mensaje'];
        }
        if ($opciones['otros_gravado'] <= 0) {
            $alertas[] = 'Renta de 5ta: si el trabajador tuvo otros empleadores este año, ingresa sus remuneraciones y retenciones certificadas (Reglamento LIR art. 41 inc. c).';
        }
        $quinta = [...$calculo, ...$opciones, 'asignado' => $hayBoleta ? $calculo['asignado'] : ($asignado ?? QuintaAjusteCese::LIQUIDACION)];

        return $this->quinta->linea($calculo);
    }

    /** @return array{total_ingresos: float, total_egresos: float, total_aportaciones: float, neto_pagar: float} */
    private function totales(array $conceptos): array
    {
        $conceptos = collect($conceptos);
        $ingresos = round($conceptos->where('tipo', 'ingreso')->sum('monto'), 2);
        $egresos = round($conceptos->where('tipo', 'egreso')->sum('monto'), 2);
        $aportaciones = round($conceptos->where('tipo', 'aportacion')->sum('monto'), 2);

        return ['total_ingresos' => $ingresos, 'total_egresos' => $egresos, 'total_aportaciones' => $aportaciones, 'neto_pagar' => round($ingresos - $egresos, 2)];
    }

    /**
     * Semestre CTS que cerró justo antes del cese y cuyo depósito puede estar
     * pendiente: [tipo, año, inicio, fin, vencimiento] (TUO CTS arts. 21-22).
     */
    private function semestreCtsCerrado(Carbon $cese): ?array
    {
        if ($cese->month >= 5 && $cese->month <= 10) {
            return ['cts_mayo', $cese->year, Carbon::create($cese->year - 1, 11, 1), Carbon::create($cese->year, 4, 30), Carbon::create($cese->year, 5, 15)];
        }
        $anio = $cese->month >= 11 ? $cese->year : $cese->year - 1;

        return ['cts_noviembre', $anio, Carbon::create($anio, 5, 1), Carbon::create($anio, 10, 31), Carbon::create($anio, 11, 15)];
    }

    private function beneficioPagado(Colaborador $colaborador, string $tipo, int $anio, ?Carbon $hasta): bool
    {
        return BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('tipo', $tipo)->where('anio', $anio)
                ->where('estado', 'pagado')->where('es_version_vigente', true)
                ->when($hasta, fn ($q2) => $q2->where('pagado_at', '<=', $hasta->copy()->endOfDay())))
            ->exists();
    }

    /** Gratificación bruta pagada (null si no hay registro, salvo $nullSiNoHay = false). */
    private function gratificacionPercibidaEn(Colaborador $colaborador, string $tipo, int $anio, Carbon $hasta, bool $nullSiNoHay = false): ?float
    {
        $detalle = BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('estado', 'pagado')->where('es_version_vigente', true)
                ->where('tipo', $tipo)->where('anio', $anio)->where('pagado_at', '<=', $hasta->copy()->endOfDay()))
            ->with('beneficioSocial')->get()->sortByDesc(fn ($d) => $d->beneficioSocial->pagado_at)->first();

        return $detalle ? (float) $detalle->bruta : ($nullSiNoHay ? null : 0.0);
    }

    private function parametros(Empresa $empresa, string $regimen, Carbon $fecha): array
    {
        try {
            return ParametrosVigentesResolver::paraRegimen($empresa, $regimen, $fecha->toDateString());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['parametros' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function validacion(string $clave, string $titulo, string $pregunta, array $opciones, string $fuente, array $conceptos, ?string $decision): array
    {
        return [
            'clave' => $clave, 'titulo' => $titulo, 'pregunta' => $pregunta, 'opciones' => $opciones, 'fuente' => $fuente,
            'bloqueante' => true, 'decision' => $decision,
            'resuelta' => $decision !== null && in_array($decision, array_column($opciones, 'value'), true),
            'conceptos' => $conceptos, 'diferencias' => [],
        ];
    }

    private function validarColaborador(Empresa $empresa, Colaborador $colaborador): void
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw ValidationException::withMessages(['colaborador' => 'El colaborador no pertenece a la empresa activa.']);
        }
        if (! $colaborador->fecha_cese) {
            throw ValidationException::withMessages(['colaborador' => 'Registra primero el cese del colaborador desde su ficha.']);
        }
        if ($colaborador->regimen_laboral === self::LOCACION) {
            throw ValidationException::withMessages(['colaborador' => 'La liquidación de beneficios sociales no aplica a locación de servicios.']);
        }
    }

    private function remuneracionAlCese(Colaborador $colaborador, Carbon $cese): ?ColaboradorRemuneracion
    {
        return ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $cese->toDateString())
            ->orderByDesc('vigencia_desde')->orderByDesc('id')->first();
    }

    private function sueldoVigente(Colaborador $colaborador, Carbon $fecha): ?float
    {
        $remuneracion = $this->remuneracionAlCese($colaborador, $fecha);

        return $remuneracion ? round((float) $remuneracion->salario, 2) : null;
    }
}
