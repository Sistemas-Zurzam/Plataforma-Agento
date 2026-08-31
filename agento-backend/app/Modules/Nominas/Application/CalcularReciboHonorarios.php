<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Nominas\Support\ProrateoIngresoTardio;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Motor de cálculo para Recibos por Honorarios (locadores, renta de 4ta
 * categoría) — deliberadamente SEPARADO de CalcularBoletaColaborador: un
 * locador no tiene relación laboral, así que no hay CTS, gratificación,
 * vacaciones, EsSalud ni AFP/ONP. Tardanzas, faltas y horas extra son
 * ajustes contractuales opcionales: solo aplican cuando su configuración
 * histórica los habilita. Comparte con la planilla dependiente:
 * el colaborador, los parámetros vigentes por fecha, el mecanismo de
 * boleta/versión/snapshot de BoletaService, y la lectura de
 * colaborador_conceptos_periodo (adelantos/descuentos operativos
 * registrados por RR.HH. — ver conceptosDelPeriodo()) — nunca las fórmulas.
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

        // Un locador que ingresa a mitad de $fechaInicio no debe facturar el
        // honorario pactado completo — el recibo real de ese período es
        // menor, y la retención de 4ta se calcula sobre el monto
        // efectivamente emitido, no sobre una tarifa mensual hipotética
        // (por eso esto se resuelve ANTES del bloque de retención, no
        // después). $honorarioBruto se deja nominal a propósito para los
        // divisores de HE (/240) y DESCUENTO_FALTA (/30) más abajo — el
        // valor-hora/valor-día no se prorratea, mismo criterio que
        // CalcularBoletaColaborador. No-op si ya estaba activo antes del
        // período — ver ProrateoIngresoTardio.
        $diasNoPagadosPorIngresoTardio = ProrateoIngresoTardio::diasNoPagados($colaborador->fecha_ingreso, $fechaInicio);
        $honorarioDelPeriodo = $diasNoPagadosPorIngresoTardio > 0
            ? round($honorarioBruto * max(0, 30 - $diasNoPagadosPorIngresoTardio) / 30, 2)
            : $honorarioBruto;

        $retencion = 0.0;
        $alertas = [];

        if ($colaborador->tiene_suspension_renta_4ta) {
            $formulaRetencion = 'Sin retención — el colaborador presentó constancia de suspensión de retenciones de renta de 4ta.';
        } elseif ($honorarioDelPeriodo <= $parametros['umbral_retencion_4ta']) {
            $formulaRetencion = "Sin retención — el honorario ({$honorarioDelPeriodo}) no supera el umbral vigente (S/ {$parametros['umbral_retencion_4ta']}).";
        } else {
            $retencion = round($honorarioDelPeriodo * $parametros['tasa_retencion_4ta'], 2);
            $formulaRetencion = "{$parametros['tasa_retencion_4ta']} × honorario del período ({$honorarioDelPeriodo}) — supera el umbral de S/ {$parametros['umbral_retencion_4ta']}";
        }

        $ingresos = [[
            'codigo' => 'HONORARIO_BRUTO',
            'monto' => round($honorarioDelPeriodo, 2),
            'base_utilizada' => null,
            'tasa_aplicada' => null,
            'cantidad' => null,
            'formula_texto' => $diasNoPagadosPorIngresoTardio > 0
                ? "Monto pactado vigente para este período — excluye {$diasNoPagadosPorIngresoTardio} día(s) previos al ingreso ({$colaborador->fecha_ingreso->toDateString()})"
                : 'Monto pactado vigente para este período (historial remunerativo del colaborador)',
        ]];

        $egresos = [[
            'codigo' => 'RETENCION_RENTA_4TA',
            'monto' => $retencion,
            'base_utilizada' => $honorarioDelPeriodo,
            'tasa_aplicada' => $colaborador->tiene_suspension_renta_4ta ? null : $parametros['tasa_retencion_4ta'],
            'cantidad' => null,
            'formula_texto' => $formulaRetencion,
        ]];

        $asistencia = $this->obtenerAsistenciaConfigurada($colaborador, $fechaInicio, $fechaFin);
        $valorHora = $honorarioBruto / 240;

        foreach ([
            ['codigo' => 'HE_25', 'horas' => $asistencia['horas_he25'], 'tasa' => $parametros['horas_extra_tasa_x25']],
            ['codigo' => 'HE_35', 'horas' => $asistencia['horas_he35'], 'tasa' => $parametros['horas_extra_tasa_x35']],
            ['codigo' => 'HE_100', 'horas' => $asistencia['horas_he100'], 'tasa' => $parametros['horas_extra_tasa_nocturna']],
        ] as $tramo) {
            if ($tramo['horas'] <= 0) {
                continue;
            }

            $ingresos[] = [
                'codigo' => $tramo['codigo'],
                'monto' => round($valorHora * $tramo['horas'] * $tramo['tasa'], 2),
                'base_utilizada' => round($valorHora, 6),
                'tasa_aplicada' => $tramo['tasa'],
                'cantidad' => $tramo['horas'],
                'formula_texto' => "Honorario/240 ({$valorHora}) × {$tramo['horas']} horas aprobadas × {$tramo['tasa']}",
            ];
        }

        if ($asistencia['minutos_tardanza'] > 0) {
            $valorMinuto = $valorHora / 60;
            $egresos[] = [
                'codigo' => 'DESCUENTO_TARDANZA',
                'monto' => round($valorMinuto * $asistencia['minutos_tardanza'], 2),
                'base_utilizada' => round($valorMinuto, 6),
                'tasa_aplicada' => null,
                'cantidad' => $asistencia['minutos_tardanza'],
                'formula_texto' => "Honorario/240/60 ({$valorMinuto}) × {$asistencia['minutos_tardanza']} minutos configurados",
            ];
        }

        if ($asistencia['dias_falta'] > 0) {
            $valorDia = $honorarioBruto / 30;
            $egresos[] = [
                'codigo' => 'DESCUENTO_FALTA',
                'monto' => round($valorDia * $asistencia['dias_falta'], 2),
                'base_utilizada' => round($valorDia, 6),
                'tasa_aplicada' => null,
                'cantidad' => $asistencia['dias_falta'],
                'formula_texto' => "Honorario/30 ({$valorDia}) × {$asistencia['dias_falta']} días configurados",
            ];
        }

        // Adelantos/descuentos operativos que RR.HH. registró para este
        // locador+ciclo (colaborador_conceptos_periodo). CicloRemunerativoService::
        // registrarConcepto ya exige tipo=egreso para honorarios, así que en la
        // práctica esto siempre cae en $egresos — pero se enruta por tipo real
        // del catálogo, nunca se asume, igual que en CalcularBoletaColaborador.
        foreach ($this->conceptosDelPeriodo($colaborador, $cicloId) as $manual) {
            if ($manual['tipo'] === 'egreso') {
                $egresos[] = $manual['linea'];
            } else {
                $ingresos[] = $manual['linea'];
            }
        }

        $totalIngresos = round(collect($ingresos)->sum('monto'), 2);
        $totalEgresos = round(collect($egresos)->sum('monto'), 2);

        return [
            'regimen_laboral' => 'Locacion de Servicios',
            'sueldo_basico' => $honorarioBruto,
            // dias_pagados no aplica a un recibo por honorarios (no hay
            // prorrateo por asistencia) — se deja en 0 porque la columna de
            // boletas no admite null, nunca debe leerse como "días trabajados".
            'dias_pagados' => 0.0,
            // Un locador puede participar en Asistencia si fue configurado
            // para ello; la boleta refleja si el período produjo resultados.
            'asistencia_procesada' => $asistencia['asistencia_procesada'],
            'dias_falta' => $asistencia['dias_falta'],
            'minutos_tardanza' => $asistencia['minutos_tardanza'],
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'aportaciones' => [],
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'total_aportaciones' => 0.0,
            'neto_a_pagar' => round($totalIngresos - $totalEgresos, 2),
            'snapshot_parametros_version' => $parametros['version_id'],
            'snapshot_reglas_version' => 'recibos-honorarios-v2-asistencia-configurable',
            'alertas' => $alertas,
        ];
    }

    /**
     * Mismo patrón que CalcularBoletaColaborador::conceptosDelPeriodo() —
     * ver ese método para el detalle de por qué el tipo se toma siempre del
     * catálogo, nunca se asume aquí.
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
                    'concepto_definicion_id' => $item->concepto_definicion_id,
                ],
            ])
            ->values()
            ->all();
    }

    private function obtenerAsistenciaConfigurada(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $condiciones = ColaboradorCondicionLaboral::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaFin)
            ->orderBy('vigencia_desde')
            ->orderBy('id')
            ->get();

        $resultados = AsistenciaResultadoDiario::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->get();

        $minutosTardanza = (int) $resultados->sum(function (AsistenciaResultadoDiario $resultado) use ($condiciones, $colaborador) {
            $config = $this->configuracionEnFecha($condiciones, $resultado->fecha->toDateString(), $colaborador);

            return $config['contabilizar_tardanzas'] ? (int) $resultado->minutos_tardanza : 0;
        });

        $diasFalta = (float) $resultados->filter(function (AsistenciaResultadoDiario $resultado) use ($condiciones, $colaborador) {
            $config = $this->configuracionEnFecha($condiciones, $resultado->fecha->toDateString(), $colaborador);

            return $config['contabilizar_faltas'] && $resultado->estado === 'falta';
        })->count();

        $minutosHe = AsistenciaHoraExtra::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where('estado', AsistenciaHoraExtra::ESTADO_APROBADO)
            ->get()
            ->filter(fn (AsistenciaHoraExtra $horaExtra) => $this->configuracionEnFecha(
                $condiciones,
                $horaExtra->fecha->toDateString(),
                $colaborador,
            )['contabilizar_horas_extra'])
            ->groupBy('tasa')
            ->map(fn (Collection $items) => (int) $items->sum('minutos_aprobados'));

        return [
            'asistencia_procesada' => $resultados->isNotEmpty(),
            'dias_falta' => $diasFalta,
            'minutos_tardanza' => $minutosTardanza,
            'horas_he25' => round((float) $minutosHe->get('25', 0) / 60, 2),
            'horas_he35' => round((float) $minutosHe->get('35', 0) / 60, 2),
            'horas_he100' => round((float) $minutosHe->get('100', 0) / 60, 2),
        ];
    }

    private function configuracionEnFecha(Collection $condiciones, string $fecha, Colaborador $colaborador): array
    {
        $vigente = $condiciones
            ->filter(fn (ColaboradorCondicionLaboral $condicion) => $condicion->vigencia_desde->toDateString() <= $fecha)
            ->last();

        return [
            'contabilizar_tardanzas' => (bool) ($vigente?->contabilizar_tardanzas ?? $colaborador->contabilizar_tardanzas ?? true),
            'contabilizar_faltas' => (bool) ($vigente?->contabilizar_faltas ?? $colaborador->contabilizar_faltas ?? true),
            'contabilizar_horas_extra' => (bool) ($vigente?->contabilizar_horas_extra ?? $colaborador->contabilizar_horas_extra ?? true),
        ];
    }
}
