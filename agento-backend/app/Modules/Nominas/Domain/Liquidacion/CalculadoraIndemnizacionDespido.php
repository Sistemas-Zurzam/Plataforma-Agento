<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

use Illuminate\Support\Carbon;

/**
 * Indemnización por despido arbitrario. Solo se calcula con causal
 * "despido_arbitrario" y superado el período de prueba; renuncia,
 * vencimiento, despido con causa, etc. nunca la activan.
 *
 * Reglas (texto oficial):
 * - Régimen general, plazo indeterminado: 1.5 remuneraciones ordinarias
 *   mensuales por año completo, fracciones por dozavos y treintavos, máximo
 *   12; procede superado el período de prueba (TUO D.Leg. 728, art. 38;
 *   período de prueba de 3 meses, art. 10).
 * - Régimen general, plazo fijo: 1.5 remuneraciones por cada mes dejado de
 *   laborar hasta el vencimiento, máximo 12 (art. 76).
 * - Microempresa: 10 remuneraciones diarias por año completo, máximo 90;
 *   pequeña empresa: 20 por año, máximo 120; fracciones de año por dozavos
 *   (TUO Ley MYPE art. 56; Ley 32353 art. 50).
 */
final class CalculadoraIndemnizacionDespido
{
    /**
     * @param  array{
     *   regimen: string, causal: ?string, tipo_contrato: ?string,
     *   fecha_ingreso: Carbon, fecha_cese: Carbon, fecha_fin_contrato: ?Carbon,
     *   remuneracion_mensual: float, periodo_prueba_meses: float, plazo_fijo_mype: ?string,
     * }  $d  plazo_fijo_mype: 'formula_mype' | 'art_76' (decisión para MYPE con contrato a plazo fijo)
     * @return array{linea: ?array<string, mixed>, alertas: array<int, string>}
     */
    public static function calcular(array $d): array
    {
        if ($d['causal'] !== 'despido_arbitrario') {
            return ['linea' => null, 'alertas' => []];
        }

        $ingreso = Carbon::parse($d['fecha_ingreso']->toDateString());
        $cese = Carbon::parse($d['fecha_cese']->toDateString());
        $dias = DiasComerciales::entre($ingreso, $cese);
        if ($dias <= $d['periodo_prueba_meses'] * 30) {
            return ['linea' => null, 'alertas' => ["Despido dentro del período de prueba ({$d['periodo_prueba_meses']} meses): no corresponde indemnización (TUO D.Leg. 728, arts. 10 y 38)."]];
        }

        $rem = $d['remuneracion_mensual'];
        $fin = $d['fecha_fin_contrato'] ? Carbon::parse($d['fecha_fin_contrato']->toDateString()) : null;
        $plazoFijo = $d['tipo_contrato'] === 'plazo_fijo' && $fin && $fin->gt($cese);
        $esMype = in_array($d['regimen'], ['Micro Empresa', 'Pequeña Empresa'], true);

        if ($plazoFijo && (! $esMype || $d['plazo_fijo_mype'] === 'art_76')) {
            $mesesFaltantes = DiasComerciales::entre($cese->copy()->addDay(), $fin) / 30;
            $monto = min(1.5 * $rem * $mesesFaltantes, 12 * $rem);

            return ['linea' => self::linea($monto, $rem, round($mesesFaltantes, 4),
                "1.5 × {$rem} × ".round($mesesFaltantes, 2).' mes(es) dejados de laborar hasta el '.$fin->format('d/m/Y').', tope 12 remuneraciones (TUO D.Leg. 728, art. 76)'), 'alertas' => []];
        }

        if ($esMype) {
            [$porAnio, $tope, $base] = $d['regimen'] === 'Micro Empresa' ? [10, 90, 'TUO Ley MYPE art. 56; Ley 32353 art. 50.1'] : [20, 120, 'TUO Ley MYPE art. 56; Ley 32353 art. 50.2'];
            $diaria = $rem / 30;
            $anios = intdiv($dias, 360) + intdiv($dias % 360, 30) / 12;
            $monto = min($porAnio * $diaria * $anios, $tope * $diaria);

            return ['linea' => self::linea($monto, $rem, round($anios, 4),
                "{$porAnio} × ({$rem} / 30) × ".round($anios, 4)." año(s) (fracción por dozavos), tope {$tope} remuneraciones diarias ({$base})"), 'alertas' => []];
        }

        $anios = $dias / 360;
        $monto = min(1.5 * $rem * $anios, 12 * $rem);

        return ['linea' => self::linea($monto, $rem, round($anios, 4),
            "1.5 × {$rem} × ".round($anios, 4).' año(s) (dozavos y treintavos), tope 12 remuneraciones (TUO D.Leg. 728, art. 38)'), 'alertas' => []];
    }

    /** @return array<string, mixed> */
    private static function linea(float $monto, float $base, float $cantidad, string $formula): array
    {
        return [
            'codigo' => 'INDEMNIZACION_DESPIDO', 'nombre' => CatalogoConceptosLiquidacion::nombre('INDEMNIZACION_DESPIDO'),
            'tipo' => 'ingreso', 'monto' => round($monto, 2), 'base_utilizada' => round($base, 2), 'cantidad' => $cantidad,
            'tasa_aplicada' => null, 'formula_texto' => $formula,
        ];
    }
}
