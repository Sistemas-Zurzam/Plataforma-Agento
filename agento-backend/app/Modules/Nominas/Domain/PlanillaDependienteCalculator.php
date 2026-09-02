<?php

namespace App\Modules\Nominas\Domain;

use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use RuntimeException;

/**
 * Implementación única para Régimen General, MYPE-Micro y MYPE-Pequeña.
 *
 * No son tres clases separadas a propósito: las tres diferencias reales
 * entre estos regímenes (CTS/gratificación reducidas o inexistentes, 15 vs.
 * 30 días de vacaciones) ya viven como PARÁMETROS distintos por régimen en
 * parametro_laboral_valores (tasa_cts, tasa_gratificacion, vacaciones_dias
 * — ver ParametrosLaborales, ya funcional) — no como una fórmula distinta.
 * Clonar esta clase 3 veces solo repetiría el mismo código con distinto
 * nombre (justo lo que CLAUDE.md pide evitar). Un régimen con una fórmula
 * genuinamente distinta (ej. Agrario con remuneración diaria, Construcción
 * Civil con jornal por categoría) sí debe implementar su propia clase —
 * ver RegimenCalculatorFactory para el punto de extensión.
 */
class PlanillaDependienteCalculator implements RegimenCalculator
{
    public function calcularBasico(float $sueldoBasico, float $diasFalta, float $horasPermisoSinGoce): array
    {
        $diasPagados = 30 - $diasFalta - ($horasPermisoSinGoce / 8);
        $monto = round(($sueldoBasico / 30) * $diasPagados, 2);
        $descuentoFalta = round(($sueldoBasico / 30) * $diasFalta, 2);
        $detalleFalta = $diasFalta > 0
            ? " — descuento por {$diasFalta} falta(s): S/ ".number_format($descuentoFalta, 2, '.', ',').' ya aplicado en el básico'
            : '';

        return [
            'dias_pagados' => $diasPagados,
            'linea' => [
                'codigo' => 'SUELDO_BASICO',
                'monto' => $monto,
                'base_utilizada' => $sueldoBasico,
                'tasa_aplicada' => null,
                'cantidad' => $diasPagados,
                'formula_texto' => "({$sueldoBasico} / 30) × {$diasPagados} días pagados{$detalleFalta}",
            ],
        ];
    }

    public function calcularHorasExtra(float $sueldoBasico, float $horas25, float $horas35, float $horas100, array $parametros): array
    {
        $valorHora = $sueldoBasico / 240;
        $lineas = [];

        $tramos = [
            ['codigo' => 'HE_25', 'horas' => $horas25, 'tasa' => $parametros['horas_extra_tasa_x25']],
            ['codigo' => 'HE_35', 'horas' => $horas35, 'tasa' => $parametros['horas_extra_tasa_x35']],
            ['codigo' => 'HE_100', 'horas' => $horas100, 'tasa' => $parametros['horas_extra_tasa_nocturna']],
        ];

        foreach ($tramos as $tramo) {
            if ($tramo['horas'] <= 0) {
                continue;
            }

            $monto = round($valorHora * $tramo['tasa'] * $tramo['horas'], 2);
            $lineas[] = [
                'codigo' => $tramo['codigo'],
                'monto' => $monto,
                'base_utilizada' => round($valorHora, 4),
                'tasa_aplicada' => $tramo['tasa'],
                'cantidad' => $tramo['horas'],
                'formula_texto' => "({$sueldoBasico}/240) × {$tramo['tasa']} × {$tramo['horas']} h",
            ];
        }

        return $lineas;
    }

    public function calcularAsignacionFamiliar(bool $calificaPorHijos, array $parametros): ?array
    {
        if (! $calificaPorHijos) {
            return null;
        }

        return [
            'codigo' => 'ASIGNACION_FAMILIAR',
            'monto' => $parametros['asignacion_familiar_monto'],
            'base_utilizada' => $parametros['rmv'],
            'tasa_aplicada' => $parametros['tasa_asignacion_familiar'],
            'cantidad' => null,
            'formula_texto' => "{$parametros['tasa_asignacion_familiar']} × RMV vigente ({$parametros['rmv']})",
        ];
    }

    public function calcularDescuentoTardanza(float $sueldoBasico, int $minutosTardanza, array $reglas = []): array
    {
        $valorMinuto = ($sueldoBasico / 240) / 60;

        if ($minutosTardanza <= 0) {
            return [
                'codigo' => 'DESCUENTO_TARDANZA',
                'monto' => 0.0,
                'base_utilizada' => round($valorMinuto, 4),
                'tasa_aplicada' => null,
                'cantidad' => 0,
                'formula_texto' => 'Sin tardanza en el período.',
            ];
        }

        $regla = collect($reglas)->first(
            fn (array $r) => $minutosTardanza >= $r['minutos_desde']
                && ($r['minutos_hasta'] === null || $minutosTardanza <= $r['minutos_hasta']),
        );

        if ($regla) {
            $monto = match ($regla['tipo']) {
                'por_minuto' => round($valorMinuto * $regla['valor'] * $minutosTardanza, 2),
                'monto_fijo' => round((float) $regla['valor'], 2),
                'medio_dia' => round(($sueldoBasico / 30) / 2, 2),
                'dia_completo' => round($sueldoBasico / 30, 2),
                default => round($valorMinuto * $minutosTardanza, 2),
            };

            return [
                'codigo' => 'DESCUENTO_TARDANZA',
                'monto' => $monto,
                'base_utilizada' => round($valorMinuto, 4),
                'tasa_aplicada' => $regla['valor'] ?? null,
                'cantidad' => $minutosTardanza,
                'formula_texto' => "Regla configurada ({$regla['minutos_desde']}-".($regla['minutos_hasta'] ?? '∞')." min, {$regla['tipo']}) → {$minutosTardanza} min tarde",
            ];
        }

        // Sin reglas configuradas para esta empresa: fórmula plana de
        // siempre, valor_minuto × minutos — comportamiento sin cambios.
        $monto = round($valorMinuto * $minutosTardanza, 2);

        return [
            'codigo' => 'DESCUENTO_TARDANZA',
            'monto' => $monto,
            'base_utilizada' => round($valorMinuto, 4),
            'tasa_aplicada' => null,
            'cantidad' => $minutosTardanza,
            'formula_texto' => "({$sueldoBasico}/240/60) × {$minutosTardanza} min",
        ];
    }

    public function calcularDescuentoHorasIncompletas(float $sueldoBasico, int $minutosHorasIncompletas): array
    {
        $valorMinuto = ($sueldoBasico / 240) / 60;

        if ($minutosHorasIncompletas <= 0) {
            return [
                'codigo' => 'DESCUENTO_HORAS_INCOMPLETAS',
                'monto' => 0.0,
                'base_utilizada' => round($valorMinuto, 4),
                'tasa_aplicada' => null,
                'cantidad' => 0,
                'formula_texto' => 'Sin horas incompletas (HI) aprobadas en el período.',
            ];
        }

        $monto = round($valorMinuto * $minutosHorasIncompletas, 2);

        return [
            'codigo' => 'DESCUENTO_HORAS_INCOMPLETAS',
            'monto' => $monto,
            'base_utilizada' => round($valorMinuto, 4),
            'tasa_aplicada' => null,
            'cantidad' => $minutosHorasIncompletas,
            'formula_texto' => "({$sueldoBasico}/240/60) × {$minutosHorasIncompletas} min de salida anticipada aprobados como Horas Incompletas (HI)",
        ];
    }

    public function calcularAporteAfpOnp(Colaborador $colaborador, float $baseRemunerativa, array $parametros, string $fechaCorte, ?float $rmaAfp = null): array
    {
        if ($colaborador->sistema_previsional === 'onp') {
            $tasa = $parametros['tasa_onp'];

            return [[
                'codigo' => 'ONP',
                'monto' => round($baseRemunerativa * $tasa, 2),
                'base_utilizada' => $baseRemunerativa,
                'tasa_aplicada' => $tasa,
                'cantidad' => null,
                'formula_texto' => "{$tasa} × base remunerativa ({$baseRemunerativa})",
            ]];
        }

        $tienAfpId = $colaborador->afp_id !== null;
        $comisionAfp = $tienAfpId
            ? ParametrosVigentesResolver::comisionAfp($colaborador->afp_id, $colaborador->tipo_comision, $fechaCorte)
            : ['aporte_obligatorio' => $parametros['tasa_afp_obligatoria'], 'prima_seguro' => 0.0, 'comision' => 0.0];

        // V3 Fase 6F.2.1 — la Remuneración Máxima Asegurable (RMA, Art. 67°
        // Título VII del Compendio de Normas Reglamentarias del SPP) topa
        // ÚNICAMENTE la base de AFP_PRIMA_SEGURO (invalidez/sobrevivencia/
        // sepelio) — nunca el aporte obligatorio ni la comisión, que siguen
        // usando $baseRemunerativa completa sin cambios (Fase 6F.2). Fallar
        // explícitamente si no hay RMA vigente en vez de calcular la prima
        // silenciosamente sobre la base sin topar (Fase 6F.2, Sección 11) —
        // este RuntimeException solo puede ocurrir para colaboradores AFP,
        // nunca para ONP (esa rama retorna antes de llegar acá).
        if ($rmaAfp === null) {
            throw new RuntimeException(
                "No hay Remuneración Máxima Asegurable (RMA) configurada para calcular la prima de seguro AFP del colaborador #{$colaborador->id} a la fecha {$fechaCorte}. ".
                'Configúrala en Configuración → Parámetros Laborales (clave "rma_afp") antes de calcular la planilla.'
            );
        }
        $basePrimaSeguro = min($baseRemunerativa, $rmaAfp);

        return [
            [
                'codigo' => 'AFP_APORTE_OBLIGATORIO',
                'monto' => round($baseRemunerativa * $comisionAfp['aporte_obligatorio'], 2),
                'base_utilizada' => $baseRemunerativa,
                'tasa_aplicada' => $comisionAfp['aporte_obligatorio'],
                'cantidad' => null,
                'formula_texto' => "{$comisionAfp['aporte_obligatorio']} × base remunerativa ({$baseRemunerativa})",
            ],
            [
                'codigo' => 'AFP_PRIMA_SEGURO',
                'monto' => round($basePrimaSeguro * $comisionAfp['prima_seguro'], 2),
                'base_utilizada' => $basePrimaSeguro,
                'tasa_aplicada' => $comisionAfp['prima_seguro'],
                'cantidad' => null,
                'formula_texto' => $basePrimaSeguro < $baseRemunerativa
                    ? "{$comisionAfp['prima_seguro']} × MIN(base remunerativa {$baseRemunerativa}, RMA {$rmaAfp}) = {$basePrimaSeguro}"
                    : "{$comisionAfp['prima_seguro']} × base remunerativa ({$basePrimaSeguro}) — no supera la RMA vigente ({$rmaAfp})",
            ],
            [
                'codigo' => 'AFP_COMISION',
                'monto' => round($baseRemunerativa * $comisionAfp['comision'], 2),
                'base_utilizada' => $baseRemunerativa,
                'tasa_aplicada' => $comisionAfp['comision'],
                'cantidad' => null,
                'formula_texto' => "{$comisionAfp['comision']} ({$colaborador->tipo_comision}) × base remunerativa ({$baseRemunerativa})",
            ],
        ];
    }

    public function calcularEsSalud(float $baseRemunerativa, array $parametros, string $seguroSalud = 'essalud'): array
    {
        if ($seguroSalud === 'sis') {
            // SIS es un monto fijo mensual por trabajador, no un % del
            // sueldo — no hay piso legal que comparar contra la base
            // remunerativa, a diferencia de EsSalud.
            return [
                'linea' => [
                    'codigo' => 'SIS_APORTACION',
                    'monto' => round($parametros['sis_monto_fijo'], 2),
                    'base_utilizada' => null,
                    'tasa_aplicada' => null,
                    'cantidad' => null,
                    'formula_texto' => "SIS — monto fijo mensual configurado (S/ {$parametros['sis_monto_fijo']}), la empresa optó por SIS en vez de EsSalud",
                ],
                'piso_activado' => false,
            ];
        }

        $calculado = round($baseRemunerativa * $parametros['tasa_essalud'], 2);
        $piso = round($parametros['rmv'] * $parametros['tasa_essalud'], 2);
        $monto = max($calculado, $piso);
        $pisoActivado = $piso > $calculado;

        return [
            'linea' => [
                'codigo' => 'ESSALUD',
                'monto' => $monto,
                'base_utilizada' => $pisoActivado ? $parametros['rmv'] : $baseRemunerativa,
                'tasa_aplicada' => $parametros['tasa_essalud'],
                'cantidad' => null,
                'formula_texto' => $pisoActivado
                    ? "MAX({$parametros['tasa_essalud']}×{$baseRemunerativa}={$calculado}, piso legal {$parametros['tasa_essalud']}×RMV({$parametros['rmv']})={$piso}) → aplica el piso"
                    : "{$parametros['tasa_essalud']} × base remunerativa ({$baseRemunerativa})",
            ],
            'piso_activado' => $pisoActivado,
        ];
    }

    public function calcularProvisiones(float $baseRemunerativaRegular, float $gratificacionesPercibidasSemestre, array $parametros): array
    {
        $lineas = [];

        // CTS — remuneración computable = base regular + 1/6 de gratificaciones
        // del semestre (Sección 2.8). tasa_cts ya vale 0%/50%/100% según el
        // régimen (micro/pequeña/general) — no se repite ese if aquí.
        if ($parametros['tasa_cts'] > 0) {
            $remuneracionComputableCts = $baseRemunerativaRegular + ($gratificacionesPercibidasSemestre / 6);
            $ctsMensual = round(($remuneracionComputableCts / 12) * $parametros['tasa_cts'], 2);
            $lineas[] = [
                'codigo' => 'CTS_PROVISION',
                'monto' => $ctsMensual,
                'base_utilizada' => round($remuneracionComputableCts, 2),
                'tasa_aplicada' => $parametros['tasa_cts'],
                'cantidad' => null,
                'formula_texto' => "({$remuneracionComputableCts}/12) × {$parametros['tasa_cts']} — provisión referencial, se deposita semestralmente",
            ];
        }

        // Gratificación — provisión mensual (1/6 del sueldo computable),
        // escalada por tasa_gratificacion (100%/50%/0%).
        if ($parametros['tasa_gratificacion'] > 0) {
            $gratificacionMensual = round(($baseRemunerativaRegular / 6) * $parametros['tasa_gratificacion'], 2);
            $lineas[] = [
                'codigo' => 'GRATIFICACION_LEGAL',
                'monto' => $gratificacionMensual,
                'base_utilizada' => $baseRemunerativaRegular,
                'tasa_aplicada' => $parametros['tasa_gratificacion'],
                'cantidad' => null,
                'formula_texto' => "({$baseRemunerativaRegular}/6) × {$parametros['tasa_gratificacion']} — provisión referencial, se paga julio/diciembre",
            ];

            if ($parametros['tasa_bonificacion_extraordinaria'] > 0) {
                $bonifExtraordinaria = round($gratificacionMensual * $parametros['tasa_bonificacion_extraordinaria'], 2);
                $lineas[] = [
                    'codigo' => 'BONIFICACION_EXTRAORDINARIA',
                    'monto' => $bonifExtraordinaria,
                    'base_utilizada' => $gratificacionMensual,
                    'tasa_aplicada' => $parametros['tasa_bonificacion_extraordinaria'],
                    'cantidad' => null,
                    'formula_texto' => "{$parametros['tasa_bonificacion_extraordinaria']} × gratificación provisionada — no remunerativa ni pensionable",
                ];
            }
        }

        // Vacaciones — provisión mensual proporcional a los días anuales que
        // corresponden por régimen (30 días → sueldo/12; 15 días → sueldo/24).
        if ($parametros['vacaciones_dias'] > 0) {
            $vacacionesMensual = round($baseRemunerativaRegular * ($parametros['vacaciones_dias'] / 30) / 12, 2);
            $lineas[] = [
                'codigo' => 'VACACIONES_PROVISION',
                'monto' => $vacacionesMensual,
                'base_utilizada' => $baseRemunerativaRegular,
                'tasa_aplicada' => null,
                'cantidad' => $parametros['vacaciones_dias'],
                'formula_texto' => "{$baseRemunerativaRegular} × ({$parametros['vacaciones_dias']}/30) / 12 — provisión referencial, se paga al gozar el descanso",
            ];
        }

        return $lineas;
    }
}
