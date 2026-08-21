<?php

namespace App\Modules\Nominas\Support;

use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Nominas\Models\TramoRenta;
use RuntimeException;

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
     * Caché en memoria por proceso (una corrida de calcularPlanilla() calcula
     * decenas/cientos de boletas con el MISMO empresa+régimen+fecha_corte —
     * sin esto se repetía la misma consulta una vez por colaborador). Se
     * reinicia solo con `limpiarCache()`, útil en tests; en producción vive
     * y muere con el request/job, nunca persiste entre ejecuciones.
     */
    private static array $cacheParametros = [];

    private static array $cacheComisionAfp = [];

    private static array $cacheTramos = [];

    public static function limpiarCache(): void
    {
        self::$cacheParametros = [];
        self::$cacheComisionAfp = [];
        self::$cacheTramos = [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paraRegimen(Empresa $empresa, string $regimenLaboral, string $fechaCorte): array
    {
        $claveCache = "{$empresa->id}:{$regimenLaboral}:{$fechaCorte}";
        if (isset(self::$cacheParametros[$claveCache])) {
            return self::$cacheParametros[$claveCache];
        }

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

        // $porDefecto = null significa "no existe un valor por defecto legal
        // seguro" — si nadie lo configuró, es un error real (nunca 0%
        // silencioso: un 0% en AFP/ONP/EsSalud/gratificación/CTS es
        // indistinguible de "se me olvidó configurarlo" y puede pasar meses
        // sin que nadie lo note). Solo los multiplicadores de horas extra y
        // la deducción de 5ta tienen un valor legal base que no cambia casi
        // nunca, así que esos sí pueden caer a un valor por defecto.
        $valor = function (string $clave, ?float $porDefecto = null) use ($valores, $definiciones, $empresa, $regimenLaboral, $fechaCorte) {
            $definicionId = $definiciones->get($clave);

            if ($definicionId === null) {
                throw new RuntimeException("El parámetro laboral \"{$clave}\" no existe en el catálogo (parametro_laboral_definiciones) — revisa el seeder.");
            }

            $valorResuelto = $valores->get($definicionId);

            if ($valorResuelto !== null) {
                return $valorResuelto;
            }

            if ($porDefecto !== null) {
                return $porDefecto;
            }

            throw new RuntimeException(
                "Falta configurar el parámetro \"{$clave}\" para el régimen \"{$regimenLaboral}\" de {$empresa->nombre}, vigente a {$fechaCorte}. ".
                'Configúralo en Configuración → Parámetros Laborales antes de calcular la planilla.'
            );
        };

        $parametros = [
            'rmv' => $valor('rmv'),
            'uit' => $valor('uit'),
            'vacaciones_dias' => (int) $valor('vacaciones_dias'),
            'tasa_essalud' => $valor('essalud_porcentaje') / 100,
            // sis_porcentaje es legado y no se usa (el SIS real es un monto
            // fijo mensual, no un % del sueldo) — sis_monto_fijo es el que
            // usa calcularEsSalud() cuando Empresa.seguro_salud = 'sis'.
            'tasa_sis' => $valor('sis_porcentaje', 0.0) / 100,
            'sis_monto_fijo' => $valor('sis_monto_fijo', 0.0),
            'tasa_onp' => $valor('onp_porcentaje') / 100,
            'tasa_afp_obligatoria' => $valor('afp_aporte_porcentaje') / 100,
            'tasa_asignacion_familiar' => $valor('asignacion_familiar_porcentaje') / 100,
            'tasa_gratificacion' => $valor('gratificacion_porcentaje') / 100,
            'tasa_cts' => $valor('cts_porcentaje') / 100,
            'tasa_bonificacion_extraordinaria' => $valor('bonificacion_extraordinaria_porcentaje', 0.0) / 100,
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

        return self::$cacheParametros[$claveCache] = $parametros;
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
        $claveCache = "{$afpId}:{$tipoComision}:{$fechaCorte}";
        if (isset(self::$cacheComisionAfp[$claveCache])) {
            return self::$cacheComisionAfp[$claveCache];
        }

        $comision = ComisionAfp::where('afp_id', $afpId)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $comision) {
            return self::$cacheComisionAfp[$claveCache] = ['aporte_obligatorio' => 0.0, 'prima_seguro' => 0.0, 'comision' => 0.0, 'vigencia_desde' => null];
        }

        $comisionPorcentaje = $tipoComision === 'mixta'
            ? (float) $comision->comision_mixta_porcentaje
            : (float) $comision->comision_flujo_porcentaje;

        return self::$cacheComisionAfp[$claveCache] = [
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
        $claveCache = "{$categoria}:{$fechaCorte}";
        if (isset(self::$cacheTramos[$claveCache])) {
            return self::$cacheTramos[$claveCache];
        }

        $ultimaVigencia = TramoRenta::where('categoria', $categoria)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->max('vigencia_desde');

        if (! $ultimaVigencia) {
            return self::$cacheTramos[$claveCache] = [];
        }

        return self::$cacheTramos[$claveCache] = TramoRenta::where('categoria', $categoria)
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
