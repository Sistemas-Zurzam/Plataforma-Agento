<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use RuntimeException;

/**
 * Motor de cálculo para Recibos por Honorarios (locadores, renta de 4ta
 * categoría) — deliberadamente SEPARADO de CalcularBoletaColaborador: un
 * locador no tiene relación laboral, así que no hay CTS, gratificación,
 * vacaciones, EsSalud, AFP/ONP, horas extra, tardanza ni prorrateo por
 * faltas. Comparte con la planilla dependiente solo lo estrictamente común:
 * el colaborador, los parámetros vigentes por fecha, y el mecanismo de
 * boleta/versión/snapshot de BoletaService — nunca las fórmulas.
 *
 * Devuelve exactamente la misma forma de array que
 * CalcularBoletaColaborador::calcular() para que BoletaService pueda
 * persistir el resultado sin duplicar esa lógica, aunque el cálculo interno
 * no tenga nada en común.
 */
class CalcularReciboHonorarios
{
    public function calcular(Colaborador $colaborador, string $fechaInicio, string $fechaFin, string $fechaCorte, ?int $cicloId = null): array
    {
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, 'Locacion de Servicios', $fechaCorte);

        $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $remuneracion) {
            throw new RuntimeException("El colaborador #{$colaborador->id} no tiene un honorario pactado vigente a {$fechaCorte}.");
        }

        $honorarioBruto = (float) $remuneracion->salario;

        $retencion = 0.0;
        $alertas = [];

        if ($colaborador->tiene_suspension_renta_4ta) {
            $formulaRetencion = 'Sin retención — el colaborador presentó constancia de suspensión de retenciones de renta de 4ta.';
        } elseif ($honorarioBruto <= $parametros['umbral_retencion_4ta']) {
            $formulaRetencion = "Sin retención — el honorario ({$honorarioBruto}) no supera el umbral vigente (S/ {$parametros['umbral_retencion_4ta']}).";
        } else {
            $retencion = round($honorarioBruto * $parametros['tasa_retencion_4ta'], 2);
            $formulaRetencion = "{$parametros['tasa_retencion_4ta']} × honorario bruto ({$honorarioBruto}) — supera el umbral de S/ {$parametros['umbral_retencion_4ta']}";
        }

        $ingresos = [[
            'codigo' => 'HONORARIO_BRUTO',
            'monto' => round($honorarioBruto, 2),
            'base_utilizada' => null,
            'tasa_aplicada' => null,
            'cantidad' => null,
            'formula_texto' => 'Monto pactado vigente para este período (historial remunerativo del colaborador)',
        ]];

        $egresos = [[
            'codigo' => 'RETENCION_RENTA_4TA',
            'monto' => $retencion,
            'base_utilizada' => $honorarioBruto,
            'tasa_aplicada' => $colaborador->tiene_suspension_renta_4ta ? null : $parametros['tasa_retencion_4ta'],
            'cantidad' => null,
            'formula_texto' => $formulaRetencion,
        ]];

        $totalIngresos = round($honorarioBruto, 2);
        $totalEgresos = $retencion;

        return [
            'regimen_laboral' => 'Locacion de Servicios',
            'sueldo_basico' => $honorarioBruto,
            // dias_pagados no aplica a un recibo por honorarios (no hay
            // prorrateo por asistencia) — se deja en 0 porque la columna de
            // boletas no admite null, nunca debe leerse como "días trabajados".
            'dias_pagados' => 0.0,
            // Un locador no tiene asistencia que procesar (no hay horario ni
            // marcaciones que le apliquen) — 'true' aquí significa "no
            // aplica", nunca "aún no procesada", así que la UI no debe
            // mostrar "Sin procesar" para un Recibo por Honorarios.
            'asistencia_procesada' => true,
            'dias_falta' => 0.0,
            'minutos_tardanza' => 0,
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'aportaciones' => [],
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'total_aportaciones' => 0.0,
            'neto_a_pagar' => round($totalIngresos - $totalEgresos, 2),
            'snapshot_parametros_version' => $parametros['version_id'],
            'snapshot_reglas_version' => 'recibos-honorarios-v1',
            'alertas' => $alertas,
        ];
    }
}
