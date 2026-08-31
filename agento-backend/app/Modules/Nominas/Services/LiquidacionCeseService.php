<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LiquidacionCeseService
{
    private const PROVISIONES = ['CTS_PROVISION', 'GRATIFICACION_LEGAL', 'BONIFICACION_EXTRAORDINARIA', 'VACACIONES_PROVISION'];

    public function __construct(
        private readonly AsistenciaOperacionService $asistencia,
        private readonly CalcularBoletaColaborador $calculadorPlanilla,
    ) {}

    /**
     * Calcula una vista reproducible a la fecha efectiva de cese. Los importes
     * no seleccionados se conservan en la respuesta con incluido=false para
     * que RR.HH. pueda comparar el efecto antes de confirmar.
     */
    public function previsualizar(Empresa $empresa, Colaborador $colaborador, string $fechaCese, array $seleccion = []): array
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw ValidationException::withMessages(['colaborador' => 'El colaborador no pertenece a la empresa activa.']);
        }

        $cese = Carbon::parse($fechaCese)->startOfDay();
        if ($cese->lt($colaborador->fecha_ingreso)) {
            throw ValidationException::withMessages(['fecha_cese' => 'La fecha de cese no puede ser anterior a la fecha de ingreso.']);
        }

        $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $cese->toDateString())
            ->orderByDesc('vigencia_desde')->orderByDesc('id')->first();
        if (! $remuneracion) {
            throw ValidationException::withMessages(['fecha_cese' => 'No existe una remuneración vigente a la fecha de cese.']);
        }

        $regimen = $colaborador->regimen_laboral ?: 'General';
        if ($regimen === 'Locacion de Servicios') {
            throw ValidationException::withMessages(['colaborador' => 'La liquidación de beneficios sociales no aplica a locación de servicios.']);
        }
        $parametros = ParametrosVigentesResolver::paraRegimen($empresa, $regimen, $cese->toDateString());
        $sueldo = (float) $remuneracion->salario;
        $incluye = fn (string $clave) => array_key_exists($clave, $seleccion) ? (bool) $seleccion[$clave] : true;
        $conceptos = [];
        $alertas = [];

        $inicioMes = $cese->copy()->startOfMonth()->max($colaborador->fecha_ingreso->copy());
        $diasMes = min(30, max(0, $inicioMes->diffInDays($cese) + 1));
        $boletaPagadaEnMes = Boleta::where('colaborador_id', $colaborador->id)
            ->where('es_version_vigente', true)->where('estado', 'pagada')
            ->whereHas('ciclo', fn ($q) => $q->whereDate('fecha_inicio', '<=', $cese->toDateString())
                ->whereDate('fecha_fin', '>=', $inicioMes->toDateString()))->exists();
        if ($incluye('incluir_remuneracion') && $boletaPagadaEnMes) {
            throw ValidationException::withMessages(['incluir_remuneracion' => 'Ya existe una boleta pagada que se superpone con el mes del cese. Desmarca la remuneración o regulariza/anula primero esa boleta.']);
        }
        $calculoMes = $this->calculadorPlanilla->calcular($colaborador, $inicioMes->toDateString(), $cese->toDateString(), $cese->toDateString());
        $diasNoPagados = max(0, 30 - (float) $calculoMes['dias_pagados']);
        $diasPagadosCese = max(0, $diasMes - $diasNoPagados);
        $montoRemuneracion = ($sueldo / 30) * $diasPagadosCese;
        $this->agregar($conceptos, 'REMUNERACION_CESE', 'Remuneración hasta la fecha de cese', $montoRemuneracion, $sueldo, $diasPagadosCese, $diasPagadosCese / 30, "({$sueldo} / 30) × {$diasPagadosCese} día(s) pagado(s)", $incluye('incluir_remuneracion'));

        $otrosIngresos = collect($calculoMes['ingresos'])->reject(fn ($linea) => in_array($linea['codigo'], [...self::PROVISIONES, 'SUELDO_BASICO', 'REMUNERACION_VACACIONAL'], true));
        foreach ($otrosIngresos as $linea) {
            $this->agregarDesdePlanilla($conceptos, $linea, $incluye('incluir_remuneracion'));
        }
        $ingresoBaseOriginal = max(0.01, collect($calculoMes['ingresos'])->reject(fn ($linea) => in_array($linea['codigo'], self::PROVISIONES, true))->sum('monto'));
        $factorEgresos = min(1, ($montoRemuneracion + $otrosIngresos->sum('monto')) / $ingresoBaseOriginal);
        foreach ($calculoMes['egresos'] as $linea) {
            $linea['monto'] = round((float) $linea['monto'] * $factorEgresos, 2);
            $linea['formula_texto'] = ($linea['formula_texto'] ?? $linea['codigo']).' — ajustado al período de cese';
            $this->agregarDesdePlanilla($conceptos, $linea, $incluye('incluir_remuneracion'), 'egreso');
        }
        if (! $calculoMes['asistencia_procesada']) {
            $alertas[] = 'La asistencia del período no está procesada; confirma faltas, permisos, tardanzas y horas extra antes de aprobar.';
        }

        $inicioSemestreGrat = $cese->month <= 6 ? $cese->copy()->startOfYear() : $cese->copy()->month(7)->startOfMonth();
        $primerMesCompleto = $colaborador->fecha_ingreso->day === 1
            ? $colaborador->fecha_ingreso->copy()->startOfMonth()
            : $colaborador->fecha_ingreso->copy()->addMonthNoOverflow()->startOfMonth();
        $inicioGrat = $inicioSemestreGrat->max($primerMesCompleto);
        $finMesComputable = $cese->isLastOfMonth() ? $cese->copy()->startOfMonth() : $cese->copy()->subMonthNoOverflow()->startOfMonth();
        $mesesGrat = $finMesComputable->lt($inicioGrat) ? 0 : ((int) $inicioGrat->diffInMonths($finMesComputable) + 1);
        $tipoGratActual = $cese->month <= 6 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $gratActualPagada = BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('tipo', $tipoGratActual)->where('anio', $cese->year)
                ->where('estado', 'pagado')->where('es_version_vigente', true))->exists();
        if ($incluye('incluir_gratificacion') && $gratActualPagada) {
            throw ValidationException::withMessages(['incluir_gratificacion' => 'La gratificación correspondiente a este semestre ya fue pagada. Desmárcala para evitar duplicarla.']);
        }
        $gratificacion = ($sueldo / 6) * $mesesGrat * $parametros['tasa_gratificacion'];
        $this->agregar($conceptos, 'GRATIFICACION_TRUNCA', 'Gratificación trunca', $gratificacion, $sueldo, $mesesGrat, $parametros['tasa_gratificacion'], "({$sueldo} / 6) × {$mesesGrat} mes(es) × {$parametros['tasa_gratificacion']}", $incluye('incluir_gratificacion'));
        $bonificacion = $gratificacion * $parametros['tasa_bonificacion_extraordinaria'];
        $this->agregar($conceptos, 'BONIFICACION_EXTRAORDINARIA_TRUNCA', 'Bonificación extraordinaria', $bonificacion, $gratificacion, null, $parametros['tasa_bonificacion_extraordinaria'], "{$gratificacion} × {$parametros['tasa_bonificacion_extraordinaria']}", $incluye('incluir_gratificacion'));

        $inicioSemestreCts = $cese->month >= 5 && $cese->month <= 10
            ? $cese->copy()->month(5)->startOfMonth()
            : ($cese->month >= 11 ? $cese->copy()->month(11)->startOfMonth() : $cese->copy()->subYear()->month(11)->startOfMonth());
        $inicioCts = $inicioSemestreCts->max($colaborador->fecha_ingreso->copy());
        $diasCts = $inicioCts->gt($cese) ? 0 : $inicioCts->diffInDays($cese) + 1;
        $tipoGratCts = $cese->month >= 5 && $cese->month <= 10 ? 'gratificacion_julio' : 'gratificacion_diciembre';
        $anioGratCts = $cese->month <= 4 ? $cese->year - 1 : $cese->year;
        $ultimaGratificacionPagada = BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->where('estado', 'pagado')->where('es_version_vigente', true)
                ->where('tipo', $tipoGratCts)->where('anio', $anioGratCts))
            ->whereHas('beneficioSocial', fn ($q) => $q->where('pagado_at', '<=', $cese))
            ->with('beneficioSocial')->get()->sortByDesc(fn ($d) => $d->beneficioSocial->pagado_at)->first();
        $gratificacionPercibida = (float) ($ultimaGratificacionPagada?->bruta ?? 0);
        if (! $ultimaGratificacionPagada && $incluye('incluir_cts')) {
            $alertas[] = 'No se encontró una gratificación pagada anterior al cese; la base CTS no incluye el sexto de gratificación percibida.';
        }
        $baseCts = $sueldo + ($gratificacionPercibida / 6);
        $cts = ($baseCts / 360) * $diasCts * $parametros['tasa_cts'];
        $this->agregar($conceptos, 'CTS_TRUNCA', 'CTS trunca', $cts, $baseCts, $diasCts, $parametros['tasa_cts'], "({$baseCts} / 360) × {$diasCts} día(s) × {$parametros['tasa_cts']}", $incluye('incluir_cts'));

        $diasServicio = $colaborador->fecha_ingreso->diffInDays($cese) + 1;
        $vacacionesTomadas = $this->asistencia->vacacionesTomadas($colaborador, $colaborador->fecha_ingreso->toDateString(), $cese->toDateString())['dias'];
        $movimientosVacaciones = (float) VacacionMovimiento::where('colaborador_id', $colaborador->id)->whereDate('fecha', '<=', $cese)->sum('dias');
        $diasVacaciones = max(0, round(($diasServicio / 360) * $parametros['vacaciones_dias'], 4) - $vacacionesTomadas + $movimientosVacaciones);
        if ($incluye('incluir_vacaciones') && ! VacacionMovimiento::where('colaborador_id', $colaborador->id)->exists()) {
            $alertas[] = 'Saldo vacacional calculado por antigüedad menos permisos aprobados; no existen ajustes en el kardex vacacional.';
        }
        $vacaciones = ($sueldo / 30) * $diasVacaciones;
        $this->agregar($conceptos, 'VACACIONES_TRUNCAS', 'Vacaciones pendientes y truncas', $vacaciones, $sueldo, $diasVacaciones, $parametros['vacaciones_dias'] / 30, "({$sueldo} / 30) × {$diasVacaciones} día(s) pendientes", $incluye('incluir_vacaciones'));

        $incluidos = collect($conceptos)->where('incluido', true);
        $totalIngresos = round($incluidos->where('tipo', 'ingreso')->sum('monto'), 2);
        $totalEgresos = round($incluidos->where('tipo', 'egreso')->sum('monto'), 2);

        return [
            'colaborador_id' => $colaborador->id,
            'fecha_cese' => $cese->toDateString(),
            'remuneracion_vigente' => round($sueldo, 2),
            'moneda' => $remuneracion->moneda_salario ?: 'PEN',
            'regimen_laboral' => $regimen,
            'conceptos' => $conceptos,
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'neto_pagar' => round($totalIngresos - $totalEgresos, 2),
            'alertas' => $alertas,
        ];
    }

    public function guardar(Empresa $empresa, Colaborador $colaborador, string $fechaCese, string $motivo, array $seleccion, int $usuarioId): LiquidacionCese
    {
        $calculo = $this->previsualizar($empresa, $colaborador, $fechaCese, $seleccion);
        // La versión se numera sobre TODO el historial del colaborador, no
        // solo la fila vigente: tras un anular-y-revertir esa fila ya quedó
        // con es_version_vigente=false, así que filtrar solo por vigente
        // reiniciaría el conteo a 1 y colisionaría con la versión anulada
        // (ambigüedad real en auditoría/UI, que muestra "vN" como referencia
        // única).
        LiquidacionCese::where('colaborador_id', $colaborador->id)->where('es_version_vigente', true)
            ->update(['es_version_vigente' => false]);
        $ultimaVersion = (int) LiquidacionCese::where('colaborador_id', $colaborador->id)->max('version');

        $liquidacion = LiquidacionCese::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_cese' => $fechaCese, 'motivo_cese' => $motivo,
            'remuneracion_snapshot' => $calculo['remuneracion_vigente'], 'regimen_laboral_snapshot' => $calculo['regimen_laboral'],
            ...$seleccion, 'total_ingresos' => $calculo['total_ingresos'], 'total_egresos' => $calculo['total_egresos'],
            'neto_pagar' => $calculo['neto_pagar'], 'alertas' => $calculo['alertas'], 'estado' => 'calculada',
            'version' => $ultimaVersion + 1, 'es_version_vigente' => true,
            'calculado_por' => $usuarioId, 'calculado_at' => now(),
        ]);
        foreach (collect($calculo['conceptos'])->where('incluido', true) as $concepto) {
            $liquidacion->conceptos()->create(collect($concepto)->except('incluido')->all());
        }
        return $liquidacion->load('conceptos');
    }

    private function agregar(array &$conceptos, string $codigo, string $nombre, float $monto, ?float $base, float|int|null $cantidad, ?float $tasa, string $formula, bool $incluido): void
    {
        $conceptos[] = ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => 'ingreso', 'monto' => round($monto, 2), 'base_utilizada' => $base === null ? null : round($base, 2), 'cantidad' => $cantidad, 'tasa_aplicada' => $tasa, 'formula_texto' => $formula, 'incluido' => $incluido];
    }

    private function agregarDesdePlanilla(array &$conceptos, array $linea, bool $incluido, string $tipo = 'ingreso'): void
    {
        $conceptos[] = [
            'codigo' => $linea['codigo'],
            'nombre' => str_replace('_', ' ', mb_convert_case($linea['codigo'], MB_CASE_TITLE)),
            'tipo' => $tipo, 'monto' => round((float) $linea['monto'], 2),
            'base_utilizada' => isset($linea['base_utilizada']) ? round((float) $linea['base_utilizada'], 2) : null,
            'cantidad' => $linea['cantidad'] ?? null, 'tasa_aplicada' => $linea['tasa_aplicada'] ?? null,
            'formula_texto' => $linea['formula_texto'] ?? null, 'incluido' => $incluido,
        ];
    }
}
