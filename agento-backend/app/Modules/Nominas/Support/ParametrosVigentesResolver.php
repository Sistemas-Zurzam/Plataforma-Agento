<?php

namespace App\Modules\Nominas\Support;

use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Nominas\Models\TramoRenta;

/**
 * Único punto de acceso a parámetros legales/laborales vigentes a una fecha
 * de corte — el motor de fórmulas NUNCA debe consultar
 * parametro_laboral_valores, comisiones_afp ni tramos_renta directamente.
 *
 * Nota de arquitectura (desviación documentada, no bloqueante): la
 * especificación pide que los parámetros legales sean una única fuente
 * nacional compartida por todo el consorcio. La implementación real de
 * Agento (ParametroLaboralValor) ya existente los modela por empresa
 * (empresa_id). No se migra esa tabla en este sprint para no romper la
 * pantalla de "Parámetros Laborales" ya funcional — cada empresa debe
 * mantener sus propios valores sincronizados. Se deja señalado en el
 * informe de entrega como PENDIENTE TÉCNICO.
 *
 * A diferencia de ParametroLaboralService::listar() (que siempre trae el
 * valor "más reciente sin importar la fecha"), aquí SÍ se filtra por
 * vigencia_desde <= fecha_corte — esto es lo que permite recalcular un
 * período pasado con el parámetro que estaba vigente en ese momento.
 */
class ParametrosVigentesResolver
{
    /**
     * @return array<string, mixed>
     */
    public static function paraRegimen(Empresa $empresa, string $regimenLaboral, string $fechaCorte): array
    {
        $definiciones = ParametroLaboralDefinicion::pluck('id', 'clave');

        $valores = ParametroLaboralValor::where('empresa_id', $empresa->id)
            ->where('regimen_laboral', $regimenLaboral)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->whereIn('definicion_id', $definiciones->values())
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->get()
            ->groupBy('definicion_id')
            ->map(fn ($grupo) => (float) $grupo->first()->valor);

        $valor = function (string $clave, float $porDefecto = 0.0) use ($valores, $definiciones) {
            $definicionId = $definiciones->get($clave);

            return $definicionId !== null ? ($valores->get($definicionId) ?? $porDefecto) : $porDefecto;
        };

        $parametros = [
            'rmv' => $valor('rmv'),
            'uit' => $valor('uit'),
            'vacaciones_dias' => (int) $valor('vacaciones_dias'),
            'tasa_essalud' => $valor('essalud_porcentaje') / 100,
            'tasa_sis' => $valor('sis_porcentaje') / 100,
            'tasa_onp' => $valor('onp_porcentaje') / 100,
            'tasa_afp_obligatoria' => $valor('afp_aporte_porcentaje') / 100,
            'tasa_asignacion_familiar' => $valor('asignacion_familiar_porcentaje') / 100,
            'tasa_gratificacion' => $valor('gratificacion_porcentaje') / 100,
            'tasa_cts' => $valor('cts_porcentaje') / 100,
            'tasa_bonificacion_extraordinaria' => $valor('bonificacion_extraordinaria_porcentaje') / 100,
            'horas_extra_tasa_x25' => $valor('horas_extra_tasa_x25', 1.25),
            'horas_extra_tasa_x35' => $valor('horas_extra_tasa_x35', 1.35),
            'horas_extra_tasa_nocturna' => $valor('horas_extra_tasa_nocturna', 2.0),
            'deduccion_5ta_uit' => $valor('deduccion_5ta_uit', 7),
            'tramos_renta_5ta' => self::tramosVigentes('quinta', $fechaCorte),
            // Recibos por Honorarios (renta de 4ta categoría) — resueltos
            // acá para cualquier régimen porque ya viven en el mismo
            // catálogo de parametro_laboral_valores; el motor de honorarios
            // (CalcularReciboHonorarios) es el único que los usa.
            'tasa_retencion_4ta' => $valor('renta_4ta_tasa') / 100,
            'umbral_retencion_4ta' => $valor('renta_4ta_umbral'),
        ];

        $parametros['asignacion_familiar_monto'] = round($parametros['rmv'] * $parametros['tasa_asignacion_familiar'], 2);
        $parametros['version_id'] = self::versionId($empresa, $regimenLaboral, $fechaCorte, $parametros);

        return $parametros;
    }

    /**
     * Comisión de AFP vigente para una administradora + tipo de comisión
     * (flujo|mixta) a una fecha de corte. Nunca hardcodear la tasa en el
     * motor de cálculo.
     *
     * @return array{aporte_obligatorio: float, prima_seguro: float, comision: float, vigencia_desde: ?string}
     */
    public static function comisionAfp(int $afpId, ?string $tipoComision, string $fechaCorte): array
    {
        $comision = ComisionAfp::where('afp_id', $afpId)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $comision) {
            return ['aporte_obligatorio' => 0.0, 'prima_seguro' => 0.0, 'comision' => 0.0, 'vigencia_desde' => null];
        }

        $comisionPorcentaje = $tipoComision === 'mixta'
            ? (float) $comision->comision_mixta_porcentaje
            : (float) $comision->comision_flujo_porcentaje;

        return [
            'aporte_obligatorio' => (float) $comision->aporte_obligatorio_porcentaje / 100,
            'prima_seguro' => (float) $comision->prima_seguro_porcentaje / 100,
            'comision' => $comisionPorcentaje / 100,
            'vigencia_desde' => $comision->vigencia_desde->toDateString(),
        ];
    }

    /**
     * @return array<int, array{limite_inferior_uit: float, limite_superior_uit: ?float, tasa: float}>
     */
    private static function tramosVigentes(string $categoria, string $fechaCorte): array
    {
        $ultimaVigencia = TramoRenta::where('categoria', $categoria)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->max('vigencia_desde');

        if (! $ultimaVigencia) {
            return [];
        }

        return TramoRenta::where('categoria', $categoria)
            ->where('vigencia_desde', $ultimaVigencia)
            ->orderBy('orden')
            ->get()
            ->map(fn (TramoRenta $tramo) => [
                'limite_inferior_uit' => (float) $tramo->limite_inferior_uit,
                'limite_superior_uit' => $tramo->limite_superior_uit !== null ? (float) $tramo->limite_superior_uit : null,
                'tasa' => (float) $tramo->tasa_porcentaje / 100,
            ])
            ->values()
            ->all();
    }

    private static function versionId(Empresa $empresa, string $regimen, string $fecha, array $parametros): string
    {
        return substr(hash('sha256', json_encode([$empresa->id, $regimen, $fecha, $parametros])), 0, 16);
    }
}
