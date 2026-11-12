<?php

namespace App\Modules\Nominas\Application\Quinta;

use App\Modules\Nominas\Application\Liquidacion\BasesVariablesLiquidacion;
use App\Modules\Nominas\Domain\Liquidacion\CatalogoConceptosLiquidacion;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\QuintaAjusteCese;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ajuste final de quinta categoría del mes del cese, único para la
 * liquidación y la boleta de ese mes (Reglamento LIR, D.S. 122-94-EF,
 * art. 41 inc. d — texto del D.S. 033-2017-EF): impuesto anual sobre todas
 * las remuneraciones y gratificaciones del ejercicio, incluidas las gravadas
 * por el cese, menos 7 UIT (TUO LIR art. 46), tasas del art. 53, menos las
 * retenciones efectuadas; el exceso se devuelve en el mes del cese
 * (art. 42 inc. a).
 *
 * Un documento lo aplica (QuintaAjusteCese.documento_asignado; por defecto
 * la boleta del mes del cese). El otro documento:
 *  - si el asignado todavía no existe, no retiene nada y muestra dónde se
 *    aplicará (nunca la retención mensual proyectada);
 *  - si el asignado ya existe, concilia: su monto es el mismo ajuste menos lo
 *    ya aplicado, es decir, solo la diferencia por los ingresos que trae.
 * En cualquier orden el total retenido del año termina igual al impuesto
 * anual. Las retenciones de documentos emitidos se toman como previas y esos
 * documentos no se modifican.
 *
 * Una planilla complementaria del mismo ejercicio (reintegro o bono puesto a
 * disposición en el mes del cese o después) también entra al ajuste: concilia
 * solo la renta que agrega frente a la versión anterior de su boleta, o
 * difiere si el documento asignado aún no existe (calcularComplementaria()).
 * Su aplicación se registra por boleta original: la versión más reciente
 * reemplaza a la anterior.
 */
class AjusteFinalQuintaCese
{
    /** Marca de las líneas de conciliación del cese dentro de un snapshot de complementaria. */
    public const MARCA_LINEA = 'ajuste_quinta_cese';

    public function __construct(private readonly BasesVariablesLiquidacion $planilla) {}

    public static function esMesDelCese(Colaborador $colaborador, string $inicio, string $fin): bool
    {
        $cese = $colaborador->fecha_cese?->toDateString();

        return $cese !== null && $cese >= $inicio && $cese <= $fin;
    }

    public function registro(Colaborador $colaborador): ?QuintaAjusteCese
    {
        return $colaborador->fecha_cese
            ? QuintaAjusteCese::where('colaborador_id', $colaborador->id)->whereDate('fecha_cese', $colaborador->fecha_cese->toDateString())->first()
            : null;
    }

    /**
     * @param  string  $documento  QuintaAjusteCese::LIQUIDACION | QuintaAjusteCese::BOLETA
     * @param  array{asignado?: ?string, ciclo_id?: ?int, otros_gravado?: ?float, otros_retenido?: ?float}  $opciones
     * @return array{aplica: bool, monto: float, ajuste: float, asignado: string, difiere: bool, existe_otro: bool, desglose: array<string, float>, mensaje: ?string}
     */
    public function calcular(Colaborador $colaborador, string $documento, float $gravadoDocumento, array $parametros, array $opciones = []): array
    {
        $anio = Carbon::parse($colaborador->fecha_cese->toDateString())->year;
        $registro = $this->registro($colaborador);
        $asignado = $opciones['asignado'] ?? $registro?->documento_asignado ?? QuintaAjusteCese::BOLETA;
        $otrosGravado = (float) ($opciones['otros_gravado'] ?? $registro?->otros_empleadores_gravado ?? 0);
        $otrosRetenido = (float) ($opciones['otros_retenido'] ?? $registro?->otros_empleadores_retenido ?? 0);

        if ($documento === QuintaAjusteCese::BOLETA) {
            // La boleta que se calcula no es previa de sí misma; la liquidación
            // vigente aporta sus ingresos gravados y lo que ya aplicó.
            $previo = $this->planilla->quintaDelAnio($colaborador, $anio, $opciones['ciclo_id'] ?? null);
            $otro = $this->liquidacionVigente($colaborador);
        } else {
            // La boleta del mes del cese, si existe, ya está dentro de las boletas del año.
            $previo = $this->planilla->quintaDelAnio($colaborador, $anio);
            $otro = $this->boletaDelCese($colaborador) ? ['gravado' => 0.0, 'aplicado' => 0.0] : null;
        }

        $resultado = $this->componer($colaborador, $anio, $previo, $otro, $gravadoDocumento, 0.0, $otrosGravado, $otrosRetenido, $parametros);
        $ajuste = $resultado['ajuste'];
        $existeOtro = $otro !== null;
        $difiere = $documento !== $asignado && ! $existeOtro;
        $monto = $difiere ? 0.0 : $ajuste;
        $mensaje = match (true) {
            $difiere => 'El ajuste final de 5ta se aplicará en la '.self::nombre($asignado).' (estimado hoy: '.($ajuste >= 0 ? 'retención' : 'devolución').' de S/ '.number_format(abs($ajuste), 2).').',
            $documento !== $asignado => 'Concilia el ajuste final ya aplicado en la '.self::nombre($asignado).': solo la diferencia por los ingresos de este documento.',
            default => null,
        };

        return [
            'aplica' => abs($monto) >= 0.01, 'monto' => $monto, 'ajuste' => $ajuste, 'asignado' => $asignado,
            'difiere' => $difiere, 'existe_otro' => $existeOtro, 'mensaje' => $mensaje, 'desglose' => $resultado['desglose'],
        ];
    }

    /**
     * Una complementaria de un colaborador cesado en el mismo ejercicio pone a
     * su disposición renta gravada (reintegros, bonos) en el mes del cese o
     * después: entra al ajuste final (Reglamento LIR art. 41 inc. d, "todo
     * otro ingreso puesto a disposición") y no a la proyección mensual del
     * art. 40, que supone meses por trabajar.
     */
    public static function aplicaAComplementaria(Colaborador $colaborador, string $inicioCiclo): bool
    {
        return $colaborador->fecha_cese !== null && $colaborador->fecha_cese->year === Carbon::parse($inicioCiclo)->year;
    }

    /** Clave de la aplicación de las complementarias de una boleta: la versión más reciente reemplaza a la anterior. */
    public static function claveComplementaria(int $boletaOriginalId): string
    {
        return 'complementaria:'.$boletaOriginalId;
    }

    /**
     * Conciliación de una complementaria. $snapshot es la versión completa de
     * la boleta que la complementaria reemplaza (así la lee quintaDelAnio()
     * una vez aprobada): solo lo que difiere de la versión anterior es
     * ingreso nuevo, y las retenciones de esa versión ya practicadas cuentan
     * como previas. Las líneas de conciliación propias se recalculan siempre
     * completas, nunca se acumulan.
     *
     * Si el documento que aplica el ajuste final todavía no existe, la
     * complementaria no retiene (difiere): ese documento contará su renta.
     *
     * @param  array{ingresos?: array, egresos?: array}  $snapshot
     * @return array{aplica: bool, monto: float, ajuste: float, asignado: string, difiere: bool, existe_otro: bool, desglose: array<string, float>, mensaje: ?string}
     */
    public function calcularComplementaria(Colaborador $colaborador, int $cicloId, array $snapshot, array $parametros): array
    {
        $anio = $colaborador->fecha_cese->year;
        $registro = $this->registro($colaborador);
        $asignado = $registro?->documento_asignado ?? QuintaAjusteCese::BOLETA;
        $liquidacion = $this->liquidacionVigente($colaborador);
        $existeAsignado = $asignado === QuintaAjusteCese::LIQUIDACION ? $liquidacion !== null : $this->boletaDelCese($colaborador) !== null;

        $propias = fn (array $l) => empty($l[self::MARCA_LINEA]);
        $afectos = ConceptoRemuneracion::whereIn('codigo', collect($snapshot['ingresos'] ?? [])->pluck('codigo')->filter()->unique())
            ->where('afecta_renta_5ta', true)->pluck('codigo')->all();
        $gravadoDocumento = (float) collect($snapshot['ingresos'] ?? [])->filter(fn ($l) => in_array($l['codigo'] ?? null, $afectos, true))->sum('monto');
        $retenidoDocumento = (float) collect($snapshot['egresos'] ?? [])->filter($propias)->where('codigo', 'RENTA_5TA')->sum('monto')
            - (float) collect($snapshot['ingresos'] ?? [])->filter($propias)->where('codigo', 'DEVOLUCION_RENTA_5TA')->sum('monto');

        $resultado = $this->componer($colaborador, $anio, $this->planilla->quintaDelAnio($colaborador, $anio, $cicloId), $liquidacion,
            $gravadoDocumento, $retenidoDocumento, (float) ($registro?->otros_empleadores_gravado ?? 0), (float) ($registro?->otros_empleadores_retenido ?? 0), $parametros);
        $ajuste = $resultado['ajuste'];
        $monto = $existeAsignado ? $ajuste : 0.0;

        return [
            'aplica' => abs($monto) >= 0.01, 'monto' => $monto, 'ajuste' => $ajuste, 'asignado' => $asignado,
            'difiere' => ! $existeAsignado, 'existe_otro' => $existeAsignado,
            'mensaje' => $existeAsignado
                ? 'Concilia el ajuste final de 5ta del cese aplicado en la '.self::nombre($asignado).': solo la diferencia por la renta que agrega esta complementaria.'
                : 'El ajuste final de 5ta se aplicará en la '.self::nombre($asignado).', que contará la renta de esta complementaria una vez aprobada.',
            'desglose' => $resultado['desglose'],
        ];
    }

    /**
     * Impuesto anual sobre toda la renta conocida menos las retenciones de
     * los demás documentos: el mismo cálculo para todos los documentos.
     *
     * @param  array{gravado: float, retenido: float}  $previo
     * @param  array{gravado: float, aplicado: float}|null  $otro
     * @return array{ajuste: float, desglose: array<string, float>}
     */
    private function componer(Colaborador $colaborador, int $anio, array $previo, ?array $otro, float $gravadoDocumento, float $retenidoDocumento, float $otrosGravado, float $otrosRetenido, array $parametros): array
    {
        $gratificaciones = (float) BeneficioSocialDetalle::where('colaborador_id', $colaborador->id)
            ->whereHas('beneficioSocial', fn ($q) => $q->whereIn('tipo', ['gratificacion_julio', 'gratificacion_diciembre'])->where('anio', $anio)
                ->where('estado', 'pagado')->where('es_version_vigente', true))
            ->get()->sum(fn ($d) => (float) $d->bruta + (float) $d->bonificacion_extraordinaria);

        $gravado = round($previo['gravado'] + $gratificaciones + $otrosGravado + ($otro['gravado'] ?? 0) + $gravadoDocumento, 2);
        $deduccion = (float) $parametros['deduccion_5ta_uit'] * (float) $parametros['uit'];
        $rentaNeta = max(0.0, $gravado - $deduccion);
        $impuesto = round($this->impuesto($rentaNeta, $parametros), 2);
        $retenciones = round($previo['retenido'] + $otrosRetenido + ($otro['aplicado'] ?? 0) + $retenidoDocumento, 2);
        $ajuste = round($impuesto - $retenciones, 2);

        return [
            'ajuste' => $ajuste,
            'desglose' => [
                'gravado_boletas' => $previo['gravado'], 'gratificaciones' => round($gratificaciones, 2), 'otros_empleadores' => $otrosGravado,
                'gravado_otro_documento' => round((float) ($otro['gravado'] ?? 0), 2), 'gravado_este_documento' => round($gravadoDocumento, 2),
                'gravado_total' => $gravado, 'deduccion_7_uit' => $deduccion, 'renta_neta' => round($rentaNeta, 2), 'impuesto_anual' => $impuesto,
                'retenido_boletas' => $previo['retenido'], 'retenido_otros_empleadores' => $otrosRetenido,
                'aplicado_otro_documento' => round((float) ($otro['aplicado'] ?? 0), 2),
                'retenido_este_documento' => round($retenidoDocumento, 2),
                'retenciones_previas' => $retenciones, 'ajuste' => $ajuste,
            ],
        ];
    }

    private static function nombre(string $documento): string
    {
        return $documento === QuintaAjusteCese::BOLETA ? 'boleta del mes del cese' : 'liquidación por cese';
    }

    /** Línea de retención (egreso) o devolución (ingreso) del documento. */
    public function linea(array $calculo): ?array
    {
        if (! $calculo['aplica']) {
            return null;
        }
        $d = $calculo['desglose'];
        $codigo = $calculo['monto'] > 0 ? 'RENTA_5TA' : 'DEVOLUCION_RENTA_5TA';

        return [
            'codigo' => $codigo, 'nombre' => CatalogoConceptosLiquidacion::nombre($codigo), 'tipo' => $calculo['monto'] > 0 ? 'egreso' : 'ingreso',
            'monto' => round(abs($calculo['monto']), 2), 'base_utilizada' => $d['renta_neta'], 'cantidad' => null, 'tasa_aplicada' => null,
            'formula_texto' => 'Ajuste final de 5ta del cese: gravado del año '.$d['gravado_total'].' (boletas '.$d['gravado_boletas'].' + gratificaciones '.$d['gratificaciones']
                .' + otros empleadores '.$d['otros_empleadores'].' + otro documento del cese '.$d['gravado_otro_documento'].' + este documento '.$d['gravado_este_documento']
                .') − '.$d['deduccion_7_uit'].' (7 UIT) → impuesto '.$d['impuesto_anual'].' − retenciones previas '.$d['retenciones_previas'].' = '.$d['ajuste']
                .' (Reglamento LIR arts. 41 d'.($calculo['monto'] < 0 ? ' y 42 a' : '').')',
        ];
    }

    /**
     * Registra (o actualiza, si se reintenta) la aplicación de un documento.
     * Serializado con el bloqueo del colaborador —el mismo que usan el
     * registro de la liquidación y el cálculo de la boleta del cese— y con
     * un registro único por cese.
     */
    public function registrarAplicacion(Colaborador $colaborador, string $documento, int $documentoId, int $version, float $monto, array $desglose, string $estado, ?string $asignado = null, ?float $otrosGravado = null, ?float $otrosRetenido = null): QuintaAjusteCese
    {
        return DB::transaction(function () use ($colaborador, $documento, $documentoId, $version, $monto, $desglose, $estado, $asignado, $otrosGravado, $otrosRetenido) {
            Colaborador::whereKey($colaborador->id)->lockForUpdate()->first();
            $registro = $this->registroBloqueado($colaborador, $asignado ?? QuintaAjusteCese::BOLETA);

            $aplicaciones = $registro->aplicaciones ?? [];
            // La asignación solo cambia mientras el documento asignado no esté emitido.
            if ($asignado && ($aplicaciones[$registro->documento_asignado]['estado'] ?? null) !== 'emitido') {
                $registro->documento_asignado = $asignado;
            }
            $aplicaciones[$documento] = [
                'id' => $documentoId, 'version' => $version, 'monto' => round($monto, 2), 'estado' => $estado,
                'desglose' => $desglose, 'registrado_at' => now()->toDateTimeString(),
            ];
            $registro->aplicaciones = $aplicaciones;
            if ($otrosGravado !== null) {
                $registro->otros_empleadores_gravado = $otrosGravado;
                $registro->otros_empleadores_retenido = (float) $otrosRetenido;
            }
            $registro->estado = $this->estado($registro);
            $registro->save();

            return $registro;
        });
    }

    /** Emisión (aprobada/pagada) o anulación de un documento ya registrado. */
    public function actualizarEstado(Colaborador $colaborador, string $documento, int $documentoId, string $estado): void
    {
        DB::transaction(function () use ($colaborador, $documento, $documentoId, $estado) {
            $registro = QuintaAjusteCese::where('colaborador_id', $colaborador->id)
                ->whereDate('fecha_cese', $colaborador->fecha_cese?->toDateString())->lockForUpdate()->first();
            $aplicaciones = $registro?->aplicaciones ?? [];
            if (! $registro || ($aplicaciones[$documento]['id'] ?? null) !== $documentoId) {
                return;
            }
            if ($estado === 'anulado') {
                unset($aplicaciones[$documento]);
            } else {
                $aplicaciones[$documento]['estado'] = $estado;
            }
            $registro->aplicaciones = $aplicaciones;
            $registro->estado = $this->estado($registro);
            $registro->save();
        });
    }

    private function registroBloqueado(Colaborador $colaborador, string $asignado): QuintaAjusteCese
    {
        $consulta = fn () => QuintaAjusteCese::where('colaborador_id', $colaborador->id)
            ->whereDate('fecha_cese', $colaborador->fecha_cese->toDateString())->lockForUpdate()->first();
        if ($registro = $consulta()) {
            return $registro;
        }
        try {
            return QuintaAjusteCese::create([
                'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
                'fecha_cese' => $colaborador->fecha_cese->toDateString(), 'anio' => $colaborador->fecha_cese->year,
                'documento_asignado' => $asignado, 'estado' => 'pendiente', 'aplicaciones' => [],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso lo creó entre la consulta y el insert: se usa ese.
            return $consulta();
        }
    }

    private function estado(QuintaAjusteCese $registro): string
    {
        return ($registro->aplicaciones[$registro->documento_asignado]['estado'] ?? null) === 'emitido' ? 'aplicado' : 'pendiente';
    }

    /** @return array{gravado: float, aplicado: float}|null */
    private function liquidacionVigente(Colaborador $colaborador): ?array
    {
        $liquidacion = LiquidacionCese::where('colaborador_id', $colaborador->id)->where('es_version_vigente', true)
            ->where('estado', '!=', 'anulada')->with('conceptos')->first();
        if (! $liquidacion) {
            return null;
        }
        $suma = fn (string $codigo) => (float) $liquidacion->conceptos->where('codigo', $codigo)->sum('monto');

        return [
            'gravado' => round($liquidacion->conceptos->filter(fn ($c) => CatalogoConceptosLiquidacion::existe($c->codigo) && CatalogoConceptosLiquidacion::afecto($c->codigo, 'afecto_5ta'))->sum('monto'), 2),
            'aplicado' => round($suma('RENTA_5TA') - $suma('DEVOLUCION_RENTA_5TA'), 2),
        ];
    }

    public function boletaDelCese(Colaborador $colaborador): ?Boleta
    {
        $cese = $colaborador->fecha_cese->toDateString();

        return Boleta::where('colaborador_id', $colaborador->id)->where('es_version_vigente', true)
            ->whereIn('estado', ['calculada', 'aprobada', 'pagada'])
            ->whereHas('ciclo', fn ($q) => $q->whereDate('fecha_inicio', '<=', $cese)->whereDate('fecha_fin', '>=', $cese))
            ->first();
    }

    private function impuesto(float $rentaNeta, array $parametros): float
    {
        $impuesto = 0.0;
        foreach ($parametros['tramos_renta_5ta'] as $tramo) {
            $inferior = $tramo['limite_inferior_uit'] * $parametros['uit'];
            $superior = $tramo['limite_superior_uit'] !== null ? $tramo['limite_superior_uit'] * $parametros['uit'] : null;
            if ($rentaNeta > $inferior) {
                $impuesto += (($superior !== null ? min($rentaNeta, $superior) : $rentaNeta) - $inferior) * $tramo['tasa'];
            }
        }

        return $impuesto;
    }
}
