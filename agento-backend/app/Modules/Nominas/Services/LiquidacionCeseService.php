<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\Liquidacion\CalculadoraBeneficiosCese;
use App\Modules\Nominas\Domain\Liquidacion\CatalogoConceptosLiquidacion;
use App\Modules\Nominas\Domain\RegimenCalculatorFactory;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\LiquidacionCeseConcepto;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Liquidación de beneficios por cese, separada del cese: el cese solo fija
 * hasta qué día se paga (la boleta del ciclo paga ese mes); aquí se liquidan
 * los beneficios acumulados. Dos modos:
 *  - asistido: el sistema propone las líneas (CalculadoraBeneficiosCese) y
 *    RR.HH. puede ajustar cada una con motivo;
 *  - manual: sin data histórica, RR.HH. ingresa las líneas calculadas fuera
 *    del sistema con su sustento, para dejar registro y pagarla.
 */
class LiquidacionCeseService
{
    private const LOCACION = 'Locacion de Servicios';

    public function __construct(private readonly AsistenciaOperacionService $asistencia) {}

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
     * entrada que el sistema no tiene (sin historial): sueldo, fecha de
     * ingreso real, gratificación percibida, vacaciones gozadas... Cada dato
     * se devuelve con su origen ('sistema' o 'manual'). Solo lectura.
     *
     * @param  array<string, mixed>  $datosManuales
     * @return array<string, mixed>
     */
    public function propuesta(Empresa $empresa, Colaborador $colaborador, array $datosManuales = []): array
    {
        $this->validarColaborador($empresa, $colaborador);
        $cese = Carbon::parse($colaborador->fecha_cese->toDateString());
        $regimen = $colaborador->regimen_laboral ?: 'General';
        $parametros = ParametrosVigentesResolver::paraRegimen($empresa, $regimen, $cese->toDateString());

        $datos = $this->resolverDatos($colaborador, $cese, $parametros, $datosManuales);
        $valor = fn (string $clave) => $datos[$clave]['valor'];
        $alertas = collect($datos)->filter(fn ($d) => $d['estado'] !== 'ok' && $d['origen'] === 'sistema')->pluck('ayuda')->values()->all();

        $base = [
            'colaborador' => [
                'id' => $colaborador->id, 'nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'documento' => $colaborador->numero_documento, 'cargo' => $colaborador->cargo,
                'fecha_ingreso' => $colaborador->fecha_ingreso->toDateString(),
                'fecha_cese' => $cese->toDateString(), 'motivo_cese' => $colaborador->motivo_cese,
            ],
            'regimen_laboral' => $regimen,
            'datos_calculo' => $datos,
        ];

        if ($valor('sueldo') === null) {
            return [...$base, 'puede_calcular' => false, 'remuneracion_computable' => null, 'conceptos' => [],
                'total_ingresos' => 0.0, 'total_egresos' => 0.0, 'neto_pagar' => 0.0, 'alertas' => $alertas];
        }

        $resultado = CalculadoraBeneficiosCese::calcular([
            'fecha_ingreso' => Carbon::parse($valor('fecha_ingreso')), 'fecha_cese' => $cese,
            'sueldo' => (float) $valor('sueldo'), 'asignacion_familiar' => (float) $valor('asignacion_familiar'),
            'gratificacion_percibida_cts' => (float) $valor('gratificacion_percibida_cts'),
            'gratificacion_semestre_pagada' => (bool) $valor('gratificacion_semestre_pagada'),
            // Ya viene neto del kardex (ver resolverDatos), así que no se suma ajuste aparte.
            'dias_vacaciones_gozados' => (float) $valor('dias_vacaciones_gozados'),
            'ajuste_vacaciones' => 0.0,
            'tasa_gratificacion' => $parametros['tasa_gratificacion'],
            'tasa_bonificacion_extraordinaria' => $parametros['tasa_bonificacion_extraordinaria'],
            'tasa_cts' => $parametros['tasa_cts'],
            'vacaciones_dias' => $parametros['vacaciones_dias'],
        ]);
        $conceptos = $resultado['conceptos'];
        $alertas = [...$alertas, ...$resultado['alertas']];
        $conceptos = [...$conceptos, ...$this->aportesSobreVacaciones($empresa, $colaborador, $regimen, $parametros, $cese, $conceptos, $alertas)];
        $alertas[] = 'La retención de renta de 5ta sobre las vacaciones no se calcula automáticamente: agrégala si corresponde.';

        return [
            ...$base,
            'puede_calcular' => true,
            'remuneracion_computable' => $resultado['remuneracion_computable'],
            'conceptos' => $conceptos,
            ...$this->totales($conceptos),
            'alertas' => $alertas,
        ];
    }

    /**
     * Datos de entrada del cálculo: valor del sistema, o el ingresado por
     * RR.HH. cuando falta o no es correcto. estado: 'ok', 'faltante' (impide
     * calcular) o 'revisar' (el sistema puede no tener el historial completo).
     *
     * @return array<string, array{etiqueta: string, tipo: string, valor: mixed, valor_sistema: mixed, origen: string, estado: string, ayuda: ?string}>
     */
    private function resolverDatos(Colaborador $colaborador, Carbon $cese, array $parametros, array $manuales): array
    {
        $remuneracion = $this->remuneracionAlCese($colaborador, $cese);

        $tipoGratActual = $cese->month <= 6 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $gratSemestrePagada = BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('tipo', $tipoGratActual)->where('anio', $cese->year)
                ->where('estado', 'pagado')->where('es_version_vigente', true))->exists();

        $tipoGratCts = $cese->month >= 5 && $cese->month <= 10 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $anioGratCts = $cese->month <= 4 ? $cese->year - 1 : $cese->year;
        $gratPercibida = BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('estado', 'pagado')->where('es_version_vigente', true)
                ->where('tipo', $tipoGratCts)->where('anio', $anioGratCts)->where('pagado_at', '<=', $cese->copy()->endOfDay()))
            ->with('beneficioSocial')->get()->sortByDesc(fn ($d) => $d->beneficioSocial->pagado_at)->first();

        $tomadas = (float) $this->asistencia->vacacionesTomadas($colaborador, $colaborador->fecha_ingreso->toDateString(), $cese->toDateString())['dias'];
        $kardex = VacacionMovimiento::where('colaborador_id', $colaborador->id)->whereDate('fecha', '<=', $cese);
        $tieneKardex = (clone $kardex)->exists();
        // Gozados netos: permisos de vacaciones aprobados menos los ajustes del
        // kardex (un ajuste positivo suma saldo, es decir, resta gozados).
        $gozadosNetos = round($tomadas - (float) $kardex->sum('dias'), 4);

        $sistema = [
            'fecha_ingreso' => ['Fecha de ingreso', 'fecha', $colaborador->fecha_ingreso->toDateString(), 'ok',
                'Si el colaborador ingresó antes de su registro en el sistema, ingresa la fecha real.'],
            'sueldo' => ['Sueldo básico al cese', 'monto', $remuneracion ? round((float) $remuneracion->salario, 2) : null,
                $remuneracion ? 'ok' : 'faltante', 'No hay una remuneración registrada a la fecha de cese: ingresa el sueldo básico.'],
            'asignacion_familiar' => ['Asignación familiar', 'monto',
                $colaborador->tiene_hijos_asignacion_familiar && $parametros['tasa_asignacion_familiar'] > 0 ? round((float) $parametros['asignacion_familiar_monto'], 2) : 0.0,
                'ok', null],
            'gratificacion_percibida_cts' => ['Gratificación percibida en el semestre de CTS', 'monto', round((float) ($gratPercibida?->bruta ?? 0), 2),
                $gratPercibida ? 'ok' : 'revisar', 'No hay una gratificación pagada registrada en el semestre de CTS (su sexto forma parte de la base de CTS). Si se pagó fuera del sistema, ingrésala.'],
            'gratificacion_semestre_pagada' => ['Gratificación del semestre del cese ya pagada', 'si_no', $gratSemestrePagada, 'ok',
                'Si ya se pagó la gratificación de este semestre, la gratificación trunca va en 0.'],
            'dias_vacaciones_gozados' => ['Días de vacaciones ya gozados (en todo el vínculo)', 'dias', $gozadosNetos,
                $tieneKardex ? 'ok' : 'revisar', 'Días gozados según permisos de vacaciones aprobados; sin ajustes en el kardex vacacional. Si gozó vacaciones antes de usar el sistema, ingresa el total real.'],
        ];

        $datos = [];
        foreach ($sistema as $clave => [$etiqueta, $tipo, $valorSistema, $estado, $ayuda]) {
            $manual = array_key_exists($clave, $manuales) && $manuales[$clave] !== null && $manuales[$clave] !== '' ? $manuales[$clave] : null;
            $valor = $valorSistema;
            $origen = 'sistema';
            if ($manual !== null) {
                $normalizado = match ($tipo) {
                    'fecha' => Carbon::parse($manual)->toDateString(),
                    'si_no' => filter_var($manual, FILTER_VALIDATE_BOOLEAN),
                    default => round((float) $manual, $tipo === 'dias' ? 4 : 2),
                };
                if ($normalizado !== $valorSistema) {
                    $valor = $normalizado;
                    $origen = 'manual';
                    $estado = 'ok';
                }
            }
            $datos[$clave] = [
                'etiqueta' => $etiqueta, 'tipo' => $tipo, 'valor' => $valor, 'valor_sistema' => $valorSistema,
                'origen' => $origen, 'estado' => $estado, 'ayuda' => $ayuda,
            ];
        }

        if ($datos['fecha_ingreso']['valor'] > $cese->toDateString()) {
            throw ValidationException::withMessages(['datos.fecha_ingreso' => 'La fecha de ingreso no puede ser posterior a la fecha de cese.']);
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

        $propuesta = $modo === LiquidacionCese::MODO_ASISTIDO ? $this->propuesta($empresa, $colaborador, $datosManuales) : null;
        if ($propuesta && ! $propuesta['puede_calcular']) {
            throw ValidationException::withMessages(['datos' => 'Faltan datos para calcular la liquidación: completa el sueldo básico al cese.']);
        }
        $usaDatosManuales = $propuesta && collect($propuesta['datos_calculo'])->contains('origen', 'manual');
        if ($usaDatosManuales && mb_strlen(trim((string) $sustento)) < 5) {
            throw ValidationException::withMessages(['sustento' => 'Indica el sustento de los datos ingresados manualmente (de dónde salen).']);
        }
        $conceptos = $this->resolverLineas($lineas, $propuesta);
        $totales = $this->totales($conceptos);
        if ($totales['total_ingresos'] <= 0) {
            throw ValidationException::withMessages(['conceptos' => 'La liquidación debe tener al menos un ingreso mayor a cero.']);
        }
        if ($totales['neto_pagar'] < 0) {
            throw ValidationException::withMessages(['conceptos' => 'Los descuentos superan a los ingresos.']);
        }

        return DB::transaction(function () use ($empresa, $colaborador, $modo, $sustento, $usuarioId, $propuesta, $conceptos, $totales) {
            Colaborador::whereKey($colaborador->id)->lockForUpdate()->firstOrFail();
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
                'remuneracion_snapshot' => round((float) ($propuesta['datos_calculo']['sueldo']['valor'] ?? $remuneracion?->salario ?? 0), 2),
                'regimen_laboral_snapshot' => $colaborador->regimen_laboral ?: 'General',
                'modo' => $modo, 'sustento' => $sustento ? trim($sustento) : null,
                'datos_calculo' => $propuesta['datos_calculo'] ?? null,
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

            return $liquidacion->load('conceptos');
        });
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

        foreach ($propuestas->keys() as $codigo) {
            if (! $recibidas->contains('codigo', $codigo)) {
                throw ValidationException::withMessages(['conceptos' => "Falta el concepto propuesto {$codigo}: para excluirlo, déjalo en 0 con el motivo."]);
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
     * AFP/ONP sobre las vacaciones (son remunerativas). Gratificación, CTS,
     * bonificación extraordinaria e indemnización vacacional no están afectas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aportesSobreVacaciones(Empresa $empresa, Colaborador $colaborador, string $regimen, array $parametros, Carbon $cese, array $conceptos, array &$alertas): array
    {
        $base = round(collect($conceptos)
            ->filter(fn ($c) => CatalogoConceptosLiquidacion::CONCEPTOS[$c['codigo']]['afecto_aportes'])
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

    /** @return array{total_ingresos: float, total_egresos: float, neto_pagar: float} */
    private function totales(array $conceptos): array
    {
        $conceptos = collect($conceptos);
        $ingresos = round($conceptos->where('tipo', 'ingreso')->sum('monto'), 2);
        $egresos = round($conceptos->where('tipo', 'egreso')->sum('monto'), 2);

        return ['total_ingresos' => $ingresos, 'total_egresos' => $egresos, 'neto_pagar' => round($ingresos - $egresos, 2)];
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
}
