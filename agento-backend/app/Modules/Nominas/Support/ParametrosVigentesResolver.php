<?php

namespace App\Modules\Nominas\Support;

use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
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

    private static array $cacheRmaAfp = [];

    public static function limpiarCache(): void
    {
        self::$cacheParametros = [];
        self::$cacheComisionAfp = [];
        self::$cacheTramos = [];
        self::$cacheRmaAfp = [];
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

        // Indexar por la clave funcional evita depender del tipo con el que
        // PDO hidrate definicion_id (int/string), que puede variar entre el
        // proceso HTTP y CLI y provocar que Collection::get() no encuentre
        // un valor que sí existe en la base.
        $valores = ParametroLaboralValor::query()
            // La empresa ya fue autorizada por el caso de uso. Un admin
            // global puede calcular una empresa distinta de la activa en su
            // usuario; conservar EmpresaScope añadiría dos empresa_id
            // contradictorios y ocultaría todos los parámetros.
            ->withoutGlobalScope(EmpresaScope::class)
            ->join('parametro_laboral_definiciones as definiciones', 'definiciones.id', '=', 'parametro_laboral_valores.definicion_id')
            ->where('parametro_laboral_valores.empresa_id', $empresa->id)
            ->where('regimen_laboral', $regimenLaboral)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->whereIn('parametro_laboral_valores.definicion_id', $definiciones->values())
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('parametro_laboral_valores.id')
            ->get(['definiciones.clave', 'parametro_laboral_valores.valor'])
            ->groupBy('clave')
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

            $valorResuelto = $valores->get($clave);

            if ($valorResuelto !== null) {
                return $valorResuelto;
            }

            if ($porDefecto !== null) {
                return $porDefecto;
            }

            throw new RuntimeException(
                "Falta configurar el parámetro \"{$clave}\" para el régimen \"{$regimenLaboral}\" de {$empresa->nombre_comercial}, vigente a {$fechaCorte}. ".
                'Configúralo en Configuración → Parámetros Laborales antes de calcular la planilla.'
            );
        };

        $multiplicadorHoraExtra = function (string $clave, float $porDefecto) use ($valor, $regimenLaboral): float {
            $resuelto = $valor($clave, $porDefecto);

            // Compatibilidad con configuraciones antiguas: LocaciÃ³n se
            // inicializaba con tasas 0 aun cuando el colaborador tenÃ­a
            // habilitado contabilizar_horas_extra. Cero no es una tasa: para
            // desactivar el pago existe la configuraciÃ³n laboral individual.
            return $regimenLaboral === 'Locacion de Servicios' && $resuelto <= 0
                ? $porDefecto
                : $resuelto;
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
            'horas_extra_tasa_x25' => $multiplicadorHoraExtra('horas_extra_tasa_x25', 1.25),
            'horas_extra_tasa_x35' => $multiplicadorHoraExtra('horas_extra_tasa_x35', 1.35),
            'horas_extra_tasa_nocturna' => $multiplicadorHoraExtra('horas_extra_tasa_nocturna', 2.0),
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
     * Parámetros mínimos del motor civil de recibos por honorarios. Un
     * locador no usa RMV, EsSalud, AFP/ONP, CTS, vacaciones ni renta de
     * quinta; exigir el paquete laboral completo impedía regularizar un RH
     * aunque los únicos valores necesarios sí estuvieran configurados.
     *
     * @return array<string, float|string>
     */
    public static function paraHonorarios(Empresa $empresa, string $fechaCorte): array
    {
        $regimen = 'Locacion de Servicios';
        $claveCache = "honorarios:{$empresa->id}:{$fechaCorte}";
        if (isset(self::$cacheParametros[$claveCache])) {
            return self::$cacheParametros[$claveCache];
        }

        $claves = [
            'renta_4ta_tasa', 'renta_4ta_umbral',
            'horas_extra_tasa_x25', 'horas_extra_tasa_x35', 'horas_extra_tasa_nocturna',
        ];
        $definiciones = ParametroLaboralDefinicion::whereIn('clave', $claves)->pluck('id', 'clave');
        $valores = ParametroLaboralValor::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
            ->where('regimen_laboral', $regimen)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->whereIn('definicion_id', $definiciones->values())
            ->orderByDesc('vigencia_desde')->orderByDesc('id')->get()
            ->groupBy('definicion_id')->map(fn ($grupo) => (float) $grupo->first()->valor);

        $requerido = function (string $clave) use ($definiciones, $valores, $empresa, $regimen, $fechaCorte): float {
            $id = $definiciones->get($clave);
            $valor = $id ? $valores->get($id) : null;
            if ($valor === null) {
                throw new RuntimeException(
                    "Falta configurar el parámetro \"{$clave}\" para el régimen \"{$regimen}\" de {$empresa->nombre_comercial}, vigente a {$fechaCorte}. ".
                    'Configúralo en Configuración → Parámetros Laborales antes de calcular la planilla.'
                );
            }
            return $valor;
        };

        $parametros = [
            'tasa_retencion_4ta' => $requerido('renta_4ta_tasa') / 100,
            'umbral_retencion_4ta' => $requerido('renta_4ta_umbral'),
            'horas_extra_tasa_x25' => $valores->get($definiciones->get('horas_extra_tasa_x25'), 1.25) ?: 1.25,
            'horas_extra_tasa_x35' => $valores->get($definiciones->get('horas_extra_tasa_x35'), 1.35) ?: 1.35,
            'horas_extra_tasa_nocturna' => $valores->get($definiciones->get('horas_extra_tasa_nocturna'), 2.0) ?: 2.0,
        ];
        $parametros['version_id'] = self::versionId($empresa, $regimen, $fechaCorte, $parametros);

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
     * V3 Fase 6F.2.1/6F.2.2/6F.2.3 — Remuneración Máxima Asegurable (RMA)
     * del SPP, tope que limita únicamente la base de AFP_PRIMA_SEGURO
     * (nunca el aporte obligatorio ni la comisión). Reutiliza exactamente
     * el mismo mecanismo de parametro_laboral_valores ya usado para
     * rmv/uit — misma desviación de arquitectura ya documentada arriba
     * (valor por empresa+régimen, no una tabla nacional única), no una
     * nueva.
     *
     * A diferencia de rmv/uit/tasas (que se resuelven por
     * fecha_corte_asistencia y así deben seguir), la RMA está normada como
     * "vigente a la fecha de pago" (Fase 6F.2, AFP Habitat/SBS) — por eso
     * este método recibe explícitamente $fechaPago, nunca $fechaCorte. El
     * llamador (CalcularBoletaColaborador) es responsable de pasar la
     * fecha correcta para cada caso.
     *
     * Devuelve null si no hay valor configurado — a propósito: quien
     * necesite tratarlo como error (solo el cálculo de AFP_PRIMA_SEGURO
     * para un afiliado AFP, nunca ONP) decide fallar explícitamente, en vez
     * de que este resolver asuma un comportamiento por defecto inseguro
     * (ej. "sin tope" o "0").
     */
    public static function rmaAfp(Empresa $empresa, string $regimenLaboral, string $fechaPago): ?float
    {
        $claveCache = "{$empresa->id}:{$regimenLaboral}:{$fechaPago}";
        if (array_key_exists($claveCache, self::$cacheRmaAfp)) {
            return self::$cacheRmaAfp[$claveCache];
        }

        $definicionId = ParametroLaboralDefinicion::where('clave', 'rma_afp')->value('id');
        if ($definicionId === null) {
            return self::$cacheRmaAfp[$claveCache] = null;
        }

        $valor = ParametroLaboralValor::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
            ->where('definicion_id', $definicionId)
            ->where('regimen_laboral', $regimenLaboral)
            ->whereDate('vigencia_desde', '<=', $fechaPago)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->value('valor');

        return self::$cacheRmaAfp[$claveCache] = $valor !== null ? (float) $valor : null;
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
