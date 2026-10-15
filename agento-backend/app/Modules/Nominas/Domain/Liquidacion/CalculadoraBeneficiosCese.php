<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

use Illuminate\Support\Carbon;

/**
 * Propuesta de beneficios truncos por cese (gratificación + bonificación
 * extraordinaria, CTS y vacaciones). Puro: no consulta base de datos, recibe
 * todo resuelto. Es una PROPUESTA: RR.HH. puede ajustar cada línea antes de
 * registrar la liquidación.
 *
 * Criterios:
 * - Remuneración computable = sueldo básico + asignación familiar.
 * - CTS y vacaciones con meses de 30 días (DiasComerciales).
 * - Gratificación por meses calendario completos del semestre.
 * - El sueldo del mes del cese NO va aquí: lo paga la boleta del ciclo.
 */
final class CalculadoraBeneficiosCese
{
    /**
     * @param  array{
     *   fecha_ingreso: Carbon, fecha_cese: Carbon, sueldo: float, asignacion_familiar: float,
     *   gratificacion_percibida_cts: float, gratificacion_semestre_pagada: bool,
     *   dias_vacaciones_gozados: float, ajuste_vacaciones: float,
     *   tasa_gratificacion: float, tasa_bonificacion_extraordinaria: float, tasa_cts: float, vacaciones_dias: int,
     * }  $d
     * @return array{conceptos: array<int, array<string, mixed>>, alertas: array<int, string>, remuneracion_computable: float}
     */
    public static function calcular(array $d): array
    {
        $ingreso = Carbon::parse($d['fecha_ingreso']->toDateString());
        $cese = Carbon::parse($d['fecha_cese']->toDateString());
        $computable = round($d['sueldo'] + $d['asignacion_familiar'], 2);
        $conceptos = [];
        $alertas = [];

        // --- Gratificación trunca (meses calendario completos del semestre) ---
        // startOfMonth() ANTES de month(): con un cese el día 31, month(11)
        // daría "31 de noviembre" y Carbon lo desbordaría al 1 de diciembre.
        $inicioSemestreGrat = $cese->month <= 6 ? $cese->copy()->startOfYear() : $cese->copy()->startOfMonth()->month(7);
        $primerMesCompleto = $ingreso->day === 1 ? $ingreso->copy()->startOfMonth() : $ingreso->copy()->addMonthNoOverflow()->startOfMonth();
        $inicioGrat = $inicioSemestreGrat->max($primerMesCompleto);
        $finMesComputable = $cese->isLastOfMonth() ? $cese->copy()->startOfMonth() : $cese->copy()->subMonthNoOverflow()->startOfMonth();
        $mesesGrat = $finMesComputable->lt($inicioGrat) ? 0 : (int) round($inicioGrat->diffInMonths($finMesComputable)) + 1;

        if ($d['gratificacion_semestre_pagada']) {
            $mesesGrat = 0;
            $alertas[] = 'La gratificación de este semestre ya figura como pagada: la gratificación trunca se propone en 0.';
        }
        $gratificacion = round(($computable / 6) * $mesesGrat * $d['tasa_gratificacion'], 2);
        $conceptos[] = self::linea('GRATIFICACION_TRUNCA', $gratificacion, $computable, $mesesGrat, $d['tasa_gratificacion'],
            "({$computable} / 6) × {$mesesGrat} mes(es) completo(s) × {$d['tasa_gratificacion']}");

        $bonificacion = round($gratificacion * $d['tasa_bonificacion_extraordinaria'], 2);
        $conceptos[] = self::linea('BONIFICACION_EXTRAORDINARIA_TRUNCA', $bonificacion, $gratificacion, null, $d['tasa_bonificacion_extraordinaria'],
            "{$gratificacion} × {$d['tasa_bonificacion_extraordinaria']}");

        // --- CTS trunca (semestres mayo-octubre / noviembre-abril) ---
        $inicioSemestreCts = match (true) {
            $cese->month >= 5 && $cese->month <= 10 => $cese->copy()->startOfMonth()->month(5),
            $cese->month >= 11 => $cese->copy()->startOfMonth()->month(11),
            default => $cese->copy()->startOfMonth()->subYear()->month(11),
        };
        $inicioCts = $inicioSemestreCts->max($ingreso);
        $diasCts = DiasComerciales::entre($inicioCts, $cese);
        if (DiasComerciales::entre($ingreso, $cese) < 30) {
            $diasCts = 0;
            $alertas[] = 'Menos de un mes de servicio: no corresponde CTS.';
        }
        $baseCts = round($computable + ($d['gratificacion_percibida_cts'] / 6), 2);
        $cts = round(($baseCts / 360) * $diasCts * $d['tasa_cts'], 2);
        $conceptos[] = self::linea('CTS_TRUNCA', $cts, $baseCts, $diasCts, $d['tasa_cts'],
            "({$baseCts} / 360) × {$diasCts} día(s) × {$d['tasa_cts']} — base: remuneración computable + 1/6 de gratificación percibida ({$d['gratificacion_percibida_cts']})");

        // --- Vacaciones ---
        $diasServicio = DiasComerciales::entre($ingreso, $cese);
        $vacDias = $d['vacaciones_dias'];
        $anios = intdiv($diasServicio, 360);
        $ganado = $diasServicio / 360 * $vacDias;
        $truncas = ($diasServicio % 360) / 360 * $vacDias;
        $saldo = max(0, $ganado - $d['dias_vacaciones_gozados'] + $d['ajuste_vacaciones']);
        // Lo gozado se descuenta primero de los récords más antiguos: si el
        // saldo no alcanza ni para las truncas, se gozaron por adelantado.
        $truncasPendientes = min($truncas, $saldo);
        $completosPendientes = max(0, $saldo - $truncasPendientes);
        // Un récord vence si no se gozó dentro del año siguiente a ganarlo:
        // el último récord completo todavía está dentro de su plazo, y nunca
        // puede haber más días vencidos que récords efectivamente vencidos
        // (un ajuste del kardex no crea récords vencidos por sí solo).
        $vencidos = min(
            max(0, $completosPendientes - ($anios >= 1 ? $vacDias : 0)),
            max(0, $anios - 1) * $vacDias,
        );
        $valorDia = $computable / 30;

        $conceptos[] = self::linea('VACACIONES_TRUNCAS', round($valorDia * $truncasPendientes, 2), $computable, round($truncasPendientes, 4), null,
            "({$computable} / 30) × ".round($truncasPendientes, 2)." día(s) del récord en curso");
        if ($completosPendientes > 0) {
            $conceptos[] = self::linea('VACACIONES_PENDIENTES', round($valorDia * $completosPendientes, 2), $computable, round($completosPendientes, 4), null,
                "({$computable} / 30) × ".round($completosPendientes, 2)." día(s) de récords completos no gozados");
        }
        if ($vencidos > 0) {
            $conceptos[] = self::linea('INDEMNIZACION_VACACIONAL', round($valorDia * $vencidos, 2), $computable, round($vencidos, 4), null,
                "({$computable} / 30) × ".round($vencidos, 2)." día(s) de récords vencidos (no gozados dentro del año siguiente)");
            $alertas[] = 'Se propone indemnización vacacional por récords vencidos: confirma en el kardex que esos días realmente no se gozaron.';
        }

        return ['conceptos' => $conceptos, 'alertas' => $alertas, 'remuneracion_computable' => $computable];
    }

    /** @return array<string, mixed> */
    private static function linea(string $codigo, float $monto, ?float $base, float|int|null $cantidad, ?float $tasa, string $formula): array
    {
        return [
            'codigo' => $codigo, 'nombre' => CatalogoConceptosLiquidacion::nombre($codigo),
            'tipo' => CatalogoConceptosLiquidacion::tipo($codigo), 'monto' => $monto,
            'base_utilizada' => $base === null ? null : round($base, 2), 'cantidad' => $cantidad,
            'tasa_aplicada' => $tasa, 'formula_texto' => $formula,
        ];
    }
}
