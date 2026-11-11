<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

use Illuminate\Support\Carbon;

/**
 * Propuesta de beneficios por cese (gratificación + bonificación
 * extraordinaria, CTS y vacaciones). Puro: no consulta base de datos, recibe
 * todo resuelto. Es una PROPUESTA: RR.HH. puede ajustar cada línea antes de
 * registrar la liquidación.
 *
 * El régimen llega por tramos (desde/hasta con sus tasas): cada mes, día o
 * récord se liquida con el régimen vigente en ese período, de modo que un
 * récord vacacional ganado bajo el régimen general conserva sus 30 días
 * aunque el vínculo termine en MYPE.
 *
 * Reglas (texto oficial):
 * - Gratificación trunca: meses calendario completos del semestre del cese,
 *   base vigente al mes inmediato anterior al cese (D.S. 005-2002-TR,
 *   num. 5.2 y 5.3). Semestre cerrado sin pago cuando el cese ocurre antes
 *   de la oportunidad de pago: proporcional a los meses del semestre (Ley
 *   27735, art. 7). Bonificación extraordinaria = aporte EsSalud (Ley 30334
 *   art. 3; 6.75 % con EPS, D.S. 007-2009-TR art. 6).
 * - CTS trunca: devenga desde el primer mes, fracciones por treintavos
 *   (TUO CTS, D.S. 001-97-TR, art. 2); período menor a un semestre se paga
 *   directo con la remuneración vigente al cese (art. 3).
 * - Vacaciones: récord trunco por dozavos y treintavos, previa acreditación
 *   de un mes de servicios (D.S. 012-92-TR, art. 23); récord no gozado en el
 *   año siguiente: remuneración + indemnización (D.Leg. 713, art. 23), que
 *   no alcanza a gerentes que decidieron no gozarlo (D.S. 012-92-TR, art. 24).
 * - Meses de 30 días para CTS y vacaciones (DiasComerciales).
 * - El sueldo del mes del cese NO va aquí: lo paga la boleta del ciclo.
 */
final class CalculadoraBeneficiosCese
{
    /**
     * @param  array{
     *   fecha_ingreso: Carbon, fecha_cese: Carbon,
     *   tramos: array<int, array{desde: Carbon, hasta: Carbon, regimen: string, tasa_gratificacion: float, tasa_bonificacion: float, tasa_cts: float, vacaciones_dias: int}>,
     *   base_gratificacion: float, base_cts: float, base_vacaciones: float,
     *   gratificacion_semestre_pagada: bool, gratificacion_semestre_anterior?: ?array{base: float, inicio: Carbon, fin: Carbon},
     *   dias_vacaciones_gozados: float, ajuste_vacaciones?: float, faltas_vacaciones_truncas?: int,
     *   excluir_indemnizacion_vacacional?: bool, cts_acumulado_rem_diarias_pequena?: ?float,
     * }  $d
     * @return array{conceptos: array<int, array<string, mixed>>, alertas: array<int, string>, remuneracion_computable: float, cts_pequena_rem_diarias: float, cts_pequena_monto: float}
     */
    public static function calcular(array $d): array
    {
        $d = self::compatibilidadRegimenUnico($d);
        $ingreso = Carbon::parse($d['fecha_ingreso']->toDateString());
        $cese = Carbon::parse($d['fecha_cese']->toDateString());
        $tramos = collect($d['tramos'])->map(fn ($t) => [...$t, 'desde' => Carbon::parse($t['desde']->toDateString()), 'hasta' => Carbon::parse($t['hasta']->toDateString())])->values()->all();
        $conceptos = [];
        $alertas = [];

        // --- Gratificación trunca (meses calendario completos del semestre) ---
        // startOfMonth() ANTES de month(): con un cese el día 31, month(11)
        // daría "31 de noviembre" y Carbon lo desbordaría al 1 de diciembre.
        $inicioSemestreGrat = $cese->month <= 6 ? $cese->copy()->startOfYear() : $cese->copy()->startOfMonth()->month(7);
        $finMesComputable = $cese->isLastOfMonth() ? $cese->copy()->startOfMonth() : $cese->copy()->subMonthNoOverflow()->startOfMonth();
        $meses = self::mesesCompletos($ingreso, $inicioSemestreGrat, $finMesComputable);
        if ($d['gratificacion_semestre_pagada']) {
            $meses = [];
            $alertas[] = 'La gratificación de este semestre ya figura como pagada: la gratificación trunca se propone en 0.';
        }
        [$gratificacion, $bonificacion, $detalle] = self::gratificacionPorMeses($meses, $d['base_gratificacion'], $tramos);
        $conceptos[] = self::linea('GRATIFICACION_TRUNCA', $gratificacion, $d['base_gratificacion'], count($meses), null,
            "({$d['base_gratificacion']} / 6) × ".count($meses)." mes(es) completo(s) del semestre{$detalle} — base vigente al mes anterior al cese (D.S. 005-2002-TR, num. 5.3)");
        $conceptos[] = self::linea('BONIFICACION_EXTRAORDINARIA_TRUNCA', $bonificacion, $gratificacion, null, null,
            "Aporte EsSalud que correspondería sobre {$gratificacion} (Ley 30334, art. 3)");

        // --- Gratificación del semestre cerrado no pagada (Ley 27735, art. 7) ---
        if ($anterior = $d['gratificacion_semestre_anterior'] ?? null) {
            $mesesAnterior = self::mesesCompletos($ingreso, Carbon::parse($anterior['inicio']->toDateString()), Carbon::parse($anterior['fin']->toDateString())->startOfMonth());
            [$gratAnterior, $bonifAnterior, $detalleAnterior] = self::gratificacionPorMeses($mesesAnterior, $anterior['base'], $tramos);
            $periodo = $anterior['inicio']->format('m/Y').'–'.$anterior['fin']->format('m/Y');
            $conceptos[] = self::linea('GRATIFICACION_SEMESTRE_ANTERIOR', $gratAnterior, $anterior['base'], count($mesesAnterior), null,
                "({$anterior['base']} / 6) × ".count($mesesAnterior)." mes(es) de {$periodo}{$detalleAnterior} — sin vínculo vigente en la oportunidad de pago (Ley 27735, art. 7)");
            $conceptos[] = self::linea('BONIFICACION_EXTRAORDINARIA_SEMESTRE_ANTERIOR', $bonifAnterior, $gratAnterior, null, null,
                "Aporte EsSalud que correspondería sobre {$gratAnterior} (Ley 30334, art. 3)");
        }

        // --- CTS trunca (semestres mayo–octubre / noviembre–abril) ---
        $inicioSemestreCts = match (true) {
            $cese->month >= 5 && $cese->month <= 10 => $cese->copy()->startOfMonth()->month(5),
            $cese->month >= 11 => $cese->copy()->startOfMonth()->month(11),
            default => $cese->copy()->startOfMonth()->subYear()->month(11),
        };
        $inicioCts = $inicioSemestreCts->max($ingreso);
        $diasCts = DiasComerciales::entre($inicioCts, $cese);
        $cts = 0.0;
        $ctsPequenaMonto = 0.0;
        $ctsPequenaRemDiarias = 0.0;
        $detalleCts = [];
        if (DiasComerciales::entre($ingreso, $cese) < 30) {
            $diasCts = 0;
            $alertas[] = 'Menos de un mes de servicio: no corresponde CTS (TUO CTS, art. 2).';
        } else {
            foreach (self::diasPorTramo($inicioCts, $cese, $tramos) as [$tramo, $dias]) {
                $monto = $d['base_cts'] / 360 * $dias * $tramo['tasa_cts'];
                $cts += $monto;
                $detalleCts[] = "{$dias} d × {$tramo['tasa_cts']} ({$tramo['regimen']})";
                if ($tramo['regimen'] === 'Pequeña Empresa') {
                    $ctsPequenaMonto += $monto;
                    $ctsPequenaRemDiarias += $dias / 360 * 30 * $tramo['tasa_cts'];
                }
            }
        }
        $tope = $d['cts_acumulado_rem_diarias_pequena'] ?? null;
        $detalleTope = '';
        if ($tope !== null && $ctsPequenaMonto > 0) {
            // TUO MYPE art. 50 / Ley 32353 art. 44.3 e): 15 rem. diarias por
            // año "hasta alcanzar un máximo de noventa".
            $disponible = max(0.0, 90 - $tope);
            $permitido = min($ctsPequenaMonto, $disponible * $d['base_cts'] / 30);
            $cts -= $ctsPequenaMonto - $permitido;
            $detalleTope = " — tope pequeña empresa: {$tope} rem. diarias ya acumuladas, quedan ".round($disponible, 4);
        }
        $cts = round($cts, 2);
        $conceptos[] = self::linea('CTS_TRUNCA', $cts, $d['base_cts'], $diasCts, null,
            "({$d['base_cts']} / 360) × [".(implode(' + ', $detalleCts) ?: '0 d').']'.$detalleTope);

        // --- Vacaciones (récords por régimen) ---
        $diasServicio = DiasComerciales::entre($ingreso, $cese);
        $anios = intdiv($diasServicio, 360);
        $records = [];
        for ($k = 1; $k <= $anios; $k++) {
            $records[] = ['dias' => self::derechoVacacional($ingreso, ($k - 1) * 360, $k * 360, $tramos), 'vencido' => $k < $anios];
        }
        $diasTrunco = $diasServicio % 360;
        $faltas = (int) ($d['faltas_vacaciones_truncas'] ?? 0);
        $truncas = 0.0;
        if ($diasServicio < 30) {
            $alertas[] = 'Menos de un mes de servicios: no corresponde récord trunco vacacional (D.S. 012-92-TR, art. 23).';
        } else {
            $truncas = self::derechoVacacional($ingreso, $anios * 360, $anios * 360 + max(0, $diasTrunco - $faltas), $tramos);
        }

        // Lo gozado se descuenta primero de los récords más antiguos; si
        // sobra, se gozaron por adelantado y reduce el trunco.
        // Gozados netos negativos = saldo trasladado (ajuste positivo del
        // kardex): se suma a los días pendientes.
        $gozados = (float) $d['dias_vacaciones_gozados'] - (float) ($d['ajuste_vacaciones'] ?? 0.0);
        $pendientes = max(0.0, -$gozados);
        $gozados = max(0.0, $gozados);
        $vencidos = 0.0;
        foreach ($records as $record) {
            $consumo = min($record['dias'], $gozados);
            $gozados -= $consumo;
            $saldo = $record['dias'] - $consumo;
            $pendientes += $saldo;
            if ($record['vencido']) {
                $vencidos += $saldo;
            }
        }
        $truncasPendientes = max(0.0, $truncas - $gozados);
        $valorDia = $d['base_vacaciones'] / 30;
        $diasRegimen = collect($tramos)->map(fn ($t) => "{$t['vacaciones_dias']} d/año ({$t['regimen']})")->unique()->implode(', ');

        $conceptos[] = self::linea('VACACIONES_TRUNCAS', round($valorDia * $truncasPendientes, 2), $d['base_vacaciones'], round($truncasPendientes, 4), null,
            "({$d['base_vacaciones']} / 30) × ".round($truncasPendientes, 2)." día(s) del récord en curso — {$diasRegimen}".($faltas > 0 ? "; {$faltas} falta(s) descontada(s) del récord" : ''));
        if ($pendientes > 0) {
            $conceptos[] = self::linea('VACACIONES_PENDIENTES', round($valorDia * $pendientes, 2), $d['base_vacaciones'], round($pendientes, 4), null,
                "({$d['base_vacaciones']} / 30) × ".round($pendientes, 2)." día(s) de récords completos no gozados — {$diasRegimen}");
        }
        if ($vencidos > 0) {
            if ($d['excluir_indemnizacion_vacacional'] ?? false) {
                $alertas[] = 'Indemnización vacacional excluida: gerente o representante que decidió no gozar el descanso (D.S. 012-92-TR, art. 24).';
            } else {
                $conceptos[] = self::linea('INDEMNIZACION_VACACIONAL', round($valorDia * $vencidos, 2), $d['base_vacaciones'], round($vencidos, 4), null,
                    "({$d['base_vacaciones']} / 30) × ".round($vencidos, 2).' día(s) de récords vencidos (D.Leg. 713, art. 23 inc. c)');
                $alertas[] = 'Se propone indemnización vacacional por récords vencidos: confirma en el kardex que esos días realmente no se gozaron.';
            }
        }

        return [
            'conceptos' => $conceptos, 'alertas' => $alertas, 'remuneracion_computable' => round($d['base_vacaciones'], 2),
            'cts_pequena_rem_diarias' => round($ctsPequenaRemDiarias, 4), 'cts_pequena_monto' => round($ctsPequenaMonto, 2),
        ];
    }

    /**
     * Entrada de un solo régimen (sueldo, asignación familiar y tasas sueltas,
     * sin tramos ni bases): se traduce a un tramo único desde el ingreso hasta
     * el cese, con remuneración computable = sueldo + asignación familiar.
     */
    private static function compatibilidadRegimenUnico(array $d): array
    {
        if (isset($d['tramos'])) {
            return $d;
        }
        $computable = round($d['sueldo'] + $d['asignacion_familiar'], 2);

        return [
            ...$d,
            'tramos' => [[
                'desde' => $d['fecha_ingreso'], 'hasta' => $d['fecha_cese'], 'regimen' => $d['regimen'] ?? 'General',
                'tasa_gratificacion' => $d['tasa_gratificacion'], 'tasa_bonificacion' => $d['tasa_bonificacion_extraordinaria'],
                'tasa_cts' => $d['tasa_cts'], 'vacaciones_dias' => $d['vacaciones_dias'],
            ]],
            'base_gratificacion' => $computable, 'base_vacaciones' => $computable,
            'base_cts' => round($computable + ($d['gratificacion_percibida_cts'] / 6), 2),
        ];
    }

    /** Días computables de CTS de un período, repartidos por tramo de régimen. */
    public static function diasPorTramo(Carbon $desde, Carbon $hasta, array $tramos): array
    {
        $resultado = [];
        foreach ($tramos as $tramo) {
            $inicio = $desde->copy()->max($tramo['desde']);
            $fin = $hasta->copy()->min($tramo['hasta']);
            if ($fin->lt($inicio)) {
                continue;
            }
            $resultado[] = [$tramo, DiasComerciales::entre($inicio, $fin)];
        }

        return $resultado;
    }

    /**
     * Meses calendario completos entre $inicio (1.º de mes) y $ultimoMes
     * (1.º del último mes computable), contados desde el primer mes completo
     * de servicios.
     *
     * @return array<int, Carbon>
     */
    private static function mesesCompletos(Carbon $ingreso, Carbon $inicio, Carbon $ultimoMes): array
    {
        $primerMesCompleto = $ingreso->day === 1 ? $ingreso->copy()->startOfMonth() : $ingreso->copy()->addMonthNoOverflow()->startOfMonth();
        $mes = $inicio->copy()->startOfMonth()->max($primerMesCompleto);
        $meses = [];
        while ($mes->lte($ultimoMes)) {
            $meses[] = $mes->copy();
            $mes->addMonthNoOverflow();
        }

        return $meses;
    }

    /** @return array{0: float, 1: float, 2: string} */
    private static function gratificacionPorMeses(array $meses, float $base, array $tramos): array
    {
        $gratificacion = 0.0;
        $bonificacion = 0.0;
        $porRegimen = [];
        foreach ($meses as $mes) {
            $tramo = self::tramoEn($mes->copy()->endOfMonth(), $tramos);
            if (! $tramo) {
                continue;
            }
            $monto = $base / 6 * $tramo['tasa_gratificacion'];
            $gratificacion += $monto;
            $bonificacion += $monto * $tramo['tasa_bonificacion'];
            $clave = "{$tramo['regimen']} × {$tramo['tasa_gratificacion']}";
            $porRegimen[$clave] = ($porRegimen[$clave] ?? 0) + 1;
        }
        $detalle = $porRegimen ? ' ['.collect($porRegimen)->map(fn ($n, $clave) => "{$n} m {$clave}")->implode('; ').']' : '';

        return [round($gratificacion, 2), round($bonificacion, 2), $detalle];
    }

    /**
     * Días de vacaciones generados en el intervalo [desde, hasta) de días de
     * servicio (0 = día de ingreso), según el régimen de cada día.
     */
    private static function derechoVacacional(Carbon $ingreso, int $desde, int $hasta, array $tramos): float
    {
        $dias = 0.0;
        foreach ($tramos as $tramo) {
            $inicio = DiasComerciales::entre($ingreso, $tramo['desde']->copy()->max($ingreso)) - 1;
            $fin = DiasComerciales::entre($ingreso, $tramo['hasta']);
            $solape = min($hasta, $fin) - max($desde, $inicio);
            if ($solape > 0) {
                $dias += $solape / 360 * $tramo['vacaciones_dias'];
            }
        }

        return $dias;
    }

    private static function tramoEn(Carbon $fecha, array $tramos): ?array
    {
        foreach ($tramos as $tramo) {
            if ($fecha->gte($tramo['desde']) && $fecha->lte($tramo['hasta']->copy()->endOfDay())) {
                return $tramo;
            }
        }

        return $tramos ? end($tramos) : null;
    }

    /** @return array<string, mixed> */
    private static function linea(string $codigo, float $monto, ?float $base, float|int|null $cantidad, ?float $tasa, string $formula): array
    {
        return [
            'codigo' => $codigo, 'nombre' => CatalogoConceptosLiquidacion::nombre($codigo),
            'tipo' => CatalogoConceptosLiquidacion::tipo($codigo), 'monto' => round($monto, 2),
            'base_utilizada' => $base === null ? null : round($base, 2), 'cantidad' => $cantidad,
            'tasa_aplicada' => $tasa, 'formula_texto' => $formula,
        ];
    }
}
