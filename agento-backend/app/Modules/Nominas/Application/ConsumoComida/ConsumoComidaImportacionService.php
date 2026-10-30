<?php

namespace App\Modules\Nominas\Application\ConsumoComida;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\ConsumoComida\ConsumoComidaFila;
use App\Modules\Nominas\Infrastructure\ConsumoComida\ConsumoComidaXlsxReader;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\ConsumoComidaAplicacion;
use App\Modules\Nominas\Models\ConsumoComidaDetalle;
use App\Modules\Nominas\Models\ConsumoComidaEvento;
use App\Modules\Nominas\Models\ConsumoComidaImportacion;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Descuentos por consumo de comida importados desde la plantilla oficial.
 *
 * Integración con el motor: aplicar() escribe, por colaborador, un
 * ColaboradorConceptoPeriodo con el concepto DESCUENTO_CONSUMO_COMIDA y la
 * suma de sus consumos. CalcularBoletaColaborador lo incorpora como egreso en
 * el próximo recálculo (sin fórmula paralela ni cambios en bases); aquí solo
 * se marca el ciclo como pendiente de recálculo. anular() elimina ese
 * ColaboradorConceptoPeriodo (el efecto) y conserva importación, consumos y
 * aplicación como historial.
 *
 * Período de consumos: el ciclo no tiene uno propio, así que se usa
 * fecha_inicio..fecha_fin del ciclo.
 */
class ConsumoComidaImportacionService
{
    public const CODIGO_CONCEPTO = 'DESCUENTO_CONSUMO_COMIDA';

    private const DISCO = 'local';

    public function __construct(
        private readonly ConsumoComidaXlsxReader $lector,
        private readonly BoletaService $boletas,
    ) {}

    public function importar(
        Empresa $empresa,
        CicloRemunerativo $ciclo,
        UploadedFile $archivo,
        string $sistemaOrigen,
        ?int $reemplazaImportacionId,
        int $usuarioId,
    ): ConsumoComidaImportacion {
        $this->verificarCiclo($empresa, $ciclo);

        if ($reemplazaImportacionId !== null) {
            $reemplazada = ConsumoComidaImportacion::whereKey($reemplazaImportacionId)
                ->where('empresa_id', $empresa->id)->where('ciclo_id', $ciclo->id)->first();
            if (! $reemplazada || $reemplazada->estado !== ConsumoComidaImportacion::ESTADO_ANULADA) {
                throw ValidationException::withMessages(['reemplaza_importacion_id' => 'Solo se puede reemplazar una importación anulada de este mismo ciclo.']);
            }
        }

        try {
            $filas = $this->lector->leer($archivo->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        $normalizadas = array_map(function (array $fila) {
            $resultado = ConsumoComidaFila::normalizar($fila['valores'], $fila['formulas']);
            if ($fila['columna_adicional']) {
                $resultado['errores'][] = 'La fila tiene datos fuera de las columnas de la plantilla.';
            }

            return [...$resultado, 'fila' => $fila['fila'], 'valores' => $fila['valores']];
        }, $filas);

        $archivoHash = hash_file('sha256', $archivo->getRealPath());
        $contenidoHash = $this->huellaContenido($normalizadas, $sistemaOrigen);
        $ruta = $archivo->storeAs("nominas/consumos-comida/{$empresa->id}/{$ciclo->id}", Str::uuid().'.xlsx', self::DISCO);

        try {
            return DB::transaction(function () use ($empresa, $ciclo, $archivo, $sistemaOrigen, $reemplazaImportacionId, $usuarioId, $normalizadas, $archivoHash, $contenidoHash, $ruta) {
                $importacion = ConsumoComidaImportacion::create([
                    'empresa_id' => $empresa->id,
                    'ciclo_id' => $ciclo->id,
                    'sistema_origen' => $sistemaOrigen,
                    'archivo_nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                    'archivo_ruta' => $ruta,
                    'archivo_hash' => $archivoHash,
                    'contenido_hash' => $contenidoHash,
                    'estado' => ConsumoComidaImportacion::ESTADO_CON_ERRORES,
                    'filas_totales' => count($normalizadas),
                    'reemplaza_importacion_id' => $reemplazaImportacionId,
                    'importado_por' => $usuarioId,
                ]);

                foreach ($normalizadas as $fila) {
                    ConsumoComidaDetalle::create([
                        'importacion_id' => $importacion->id,
                        'empresa_id' => $empresa->id,
                        'fila' => $fila['fila'],
                        'documento' => $fila['documento'],
                        'fecha_consumo' => $fila['fecha_consumo'],
                        'concepto' => $fila['concepto'],
                        'sistema_origen' => $sistemaOrigen,
                        'referencia' => $fila['referencia'],
                        'monto' => $fila['monto_centavos'] !== null ? ConsumoComidaFila::centavosATexto($fila['monto_centavos']) : null,
                        'valores_originales' => array_map(fn ($valor) => is_scalar($valor) ? $valor : null, $fila['valores']),
                        'estado' => $fila['errores'] === [] ? ConsumoComidaDetalle::ESTADO_VALIDO : ConsumoComidaDetalle::ESTADO_ERROR,
                        'errores' => $fila['errores'],
                        'errores_formato' => $fila['errores'],
                    ]);
                }

                $this->evaluar($importacion);
                $this->registrarEvento($importacion, $usuarioId, 'importacion_validada', null, [
                    'archivo' => $importacion->archivo_nombre_original, 'estado' => $importacion->estado,
                    'filas' => $importacion->filas_totales, 'monto_total' => (string) $importacion->monto_total,
                ]);

                return $importacion->fresh();
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISCO)->delete($ruta);

            throw $e;
        }
    }

    /**
     * Admite como consumos legítimos filas marcadas como posible duplicado
     * (sin referencia, mismo documento/fecha/concepto/monto). Exige motivo y
     * deja auditoría por fila.
     *
     * @param  array<int, int>  $detalleIds
     */
    public function admitirDuplicados(Empresa $empresa, ConsumoComidaImportacion $importacion, array $detalleIds, string $motivo, int $usuarioId): ConsumoComidaImportacion
    {
        $this->verificarImportacion($empresa, $importacion);
        if ($importacion->estado !== ConsumoComidaImportacion::ESTADO_VALIDADA) {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden revisar duplicados de una importación validada.']);
        }

        return DB::transaction(function () use ($importacion, $detalleIds, $motivo, $usuarioId) {
            $detalles = $importacion->detalles()->whereIn('id', $detalleIds)
                ->where('estado', ConsumoComidaDetalle::ESTADO_POSIBLE_DUPLICADO)->get();
            if ($detalles->count() !== count(array_unique($detalleIds))) {
                throw ValidationException::withMessages(['detalle_ids' => 'Solo se pueden admitir filas de esta importación marcadas como posible duplicado.']);
            }

            foreach ($detalles as $detalle) {
                $detalle->update(['duplicado_admitido_por' => $usuarioId, 'duplicado_admitido_at' => now(), 'duplicado_motivo' => $motivo]);
            }
            $this->evaluar($importacion);
            $this->registrarEvento($importacion, $usuarioId, 'duplicados_admitidos', $motivo, ['filas' => $detalles->pluck('fila')->all()]);

            return $importacion->fresh();
        });
    }

    /**
     * Confirma la importación. Idempotente: una segunda llamada (doble clic,
     * reintento) sobre una importación ya aplicada la devuelve sin crear
     * descuentos adicionales. Revalida todo dentro de la transacción, con el
     * ciclo bloqueado para que no compita con su cierre.
     */
    public function aplicar(Empresa $empresa, ConsumoComidaImportacion $importacion, int $usuarioId, bool $confirmarBoletasAprobadas = false): ConsumoComidaImportacion
    {
        $this->verificarImportacion($empresa, $importacion);

        $resultado = DB::transaction(function () use ($empresa, $importacion, $usuarioId, $confirmarBoletasAprobadas) {
            $importacion = ConsumoComidaImportacion::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if ($importacion->estado === ConsumoComidaImportacion::ESTADO_APLICADA) {
                return ['importacion' => $importacion];
            }
            if ($importacion->estado !== ConsumoComidaImportacion::ESTADO_VALIDADA) {
                throw ValidationException::withMessages(['estado' => 'Solo se puede aplicar una importación validada y sin errores.']);
            }

            $ciclo = CicloRemunerativo::whereKey($importacion->ciclo_id)->lockForUpdate()->firstOrFail();
            $this->verificarCicloModificable($ciclo);
            $concepto = $this->conceptoConfigurado();

            // Revalidación con los datos actuales: colaboradores, período,
            // referencias y duplicados pueden haber cambiado desde la carga.
            $this->evaluar($importacion);
            if ($importacion->estado !== ConsumoComidaImportacion::ESTADO_VALIDADA) {
                return ['importacion' => $importacion, 'error' => 'La importación ya no es válida con los datos actuales; revisa los errores antes de confirmar.'];
            }
            if ($importacion->filas_posible_duplicado > 0) {
                return ['importacion' => $importacion, 'error' => 'Hay posibles duplicados sin revisar: admítelos con un motivo o corrige el archivo.'];
            }

            $porColaborador = $this->totalesPorColaborador($importacion);
            $this->verificarBoletas($ciclo, $porColaborador->keys(), $confirmarBoletasAprobadas);

            $ahora = now();
            foreach ($porColaborador as $colaboradorId => $total) {
                $conceptoPeriodo = ColaboradorConceptoPeriodo::create([
                    'empresa_id' => $empresa->id,
                    'ciclo_id' => $ciclo->id,
                    'colaborador_id' => $colaboradorId,
                    'concepto_id' => $concepto->id,
                    'monto' => ConsumoComidaFila::centavosATexto($total['centavos']),
                    // Sin motivo: la boleta muestra "Adelanto 2" como cualquier
                    // adelanto. La trazabilidad vive en ConsumoComidaAplicacion.
                    'motivo' => null,
                    'creado_por' => $usuarioId,
                ]);
                ConsumoComidaAplicacion::create([
                    'importacion_id' => $importacion->id,
                    'empresa_id' => $empresa->id,
                    'ciclo_id' => $ciclo->id,
                    'colaborador_id' => $colaboradorId,
                    'monto' => ConsumoComidaFila::centavosATexto($total['centavos']),
                    'consumos' => $total['consumos'],
                    'colaborador_concepto_periodo_id' => $conceptoPeriodo->id,
                    'estado' => ConsumoComidaAplicacion::ESTADO_APLICADA,
                    'aplicado_at' => $ahora,
                ]);
            }

            // El índice único (empresa, sistema de origen, referencia_activa)
            // es la última barrera contra aplicar dos veces la misma referencia.
            $importacion->detalles()->whereNotNull('referencia')->update(['referencia_activa' => DB::raw('referencia')]);

            $importacion->update([
                'estado' => ConsumoComidaImportacion::ESTADO_APLICADA,
                'aplicado_por' => $usuarioId,
                'aplicado_at' => $ahora,
            ]);
            $this->marcarRecalculo($ciclo, "Consumo de comida: se aplicó la importación #{$importacion->id} (S/ {$importacion->monto_total}).");
            $this->registrarEvento($importacion, $usuarioId, 'importacion_aplicada', null, [
                'colaboradores' => $porColaborador->count(), 'monto_total' => (string) $importacion->monto_total,
                'boletas_aprobadas_confirmadas' => $confirmarBoletasAprobadas,
            ]);

            return ['importacion' => $importacion->fresh()];
        });

        if (isset($resultado['error'])) {
            throw ValidationException::withMessages(['importacion' => $resultado['error']]);
        }

        return $resultado['importacion'];
    }

    /**
     * Retira el efecto de una importación aplicada (elimina sus
     * ColaboradorConceptoPeriodo) sin borrar importación, consumos ni
     * aplicaciones. Una importación no aplicada se anula sin efecto que retirar.
     */
    public function anular(Empresa $empresa, ConsumoComidaImportacion $importacion, string $motivo, int $usuarioId, bool $confirmarBoletasAprobadas = false): ConsumoComidaImportacion
    {
        $this->verificarImportacion($empresa, $importacion);

        return DB::transaction(function () use ($importacion, $motivo, $usuarioId, $confirmarBoletasAprobadas) {
            $importacion = ConsumoComidaImportacion::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if ($importacion->estado === ConsumoComidaImportacion::ESTADO_ANULADA) {
                return $importacion;
            }
            $estabaAplicada = $importacion->estado === ConsumoComidaImportacion::ESTADO_APLICADA;

            if ($estabaAplicada) {
                $ciclo = CicloRemunerativo::whereKey($importacion->ciclo_id)->lockForUpdate()->firstOrFail();
                $this->verificarCicloModificable($ciclo);
                $aplicaciones = $importacion->aplicaciones()->where('estado', ConsumoComidaAplicacion::ESTADO_APLICADA)->get();
                $this->verificarBoletas($ciclo, $aplicaciones->pluck('colaborador_id'), $confirmarBoletasAprobadas);

                $ahora = now();
                ColaboradorConceptoPeriodo::whereIn('id', $aplicaciones->pluck('colaborador_concepto_periodo_id')->filter())->delete();
                $importacion->aplicaciones()->where('estado', ConsumoComidaAplicacion::ESTADO_APLICADA)
                    ->update(['estado' => ConsumoComidaAplicacion::ESTADO_ANULADA, 'anulado_at' => $ahora]);
                $importacion->detalles()->update(['referencia_activa' => null]);
                $this->marcarRecalculo($ciclo, "Consumo de comida: se anuló la importación #{$importacion->id}.");
            }

            $importacion->update([
                'estado' => ConsumoComidaImportacion::ESTADO_ANULADA,
                'anulado_por' => $usuarioId,
                'anulado_at' => now(),
                'motivo_anulacion' => $motivo,
            ]);
            $this->registrarEvento($importacion, $usuarioId, 'importacion_anulada', $motivo, ['estaba_aplicada' => $estabaAplicada]);

            return $importacion->fresh();
        });
    }

    /**
     * Vista previa de la confirmación: resumen por colaborador con su boleta
     * vigente y los bloqueos que impedirían aplicar. Calculado siempre en el
     * servidor.
     *
     * @return array<string, mixed>
     */
    public function previsualizacion(ConsumoComidaImportacion $importacion): array
    {
        $ciclo = $importacion->ciclo;
        $porColaborador = $this->totalesPorColaborador($importacion);
        $colaboradores = Colaborador::withTrashed()->whereIn('id', $porColaborador->keys())->get()->keyBy('id');
        $boletas = $this->boletasVigentes($ciclo, $porColaborador->keys());

        $resumen = $porColaborador->map(function (array $total, int $colaboradorId) use ($colaboradores, $boletas) {
            $colaborador = $colaboradores->get($colaboradorId);
            $boleta = $boletas->get($colaboradorId);
            $netoEstimado = $boleta ? ConsumoComidaFila::textoACentavos((string) $boleta->neto_a_pagar) - $total['centavos'] : null;

            return [
                'colaborador_id' => $colaboradorId,
                'documento' => $colaborador?->numero_documento,
                'nombre' => $colaborador ? trim("{$colaborador->nombres} {$colaborador->apellidos}") : null,
                'consumos' => $total['consumos'],
                'monto' => ConsumoComidaFila::centavosATexto($total['centavos']),
                'boleta' => $boleta ? ['id' => $boleta->id, 'version' => $boleta->version, 'estado' => $boleta->estado, 'neto_a_pagar' => (string) $boleta->neto_a_pagar] : null,
                // Estimación: neto vigente menos este descuento. El neto real
                // lo calcula el motor al recalcular.
                'neto_estimado' => $netoEstimado !== null ? ($netoEstimado < 0 ? '-'.ConsumoComidaFila::centavosATexto(-$netoEstimado) : ConsumoComidaFila::centavosATexto($netoEstimado)) : null,
                'conflicto_neto' => $netoEstimado !== null && $netoEstimado < 0,
            ];
        })->values();

        return [
            'colaboradores' => $resumen,
            'bloqueos' => $this->bloqueos($importacion, $ciclo, $boletas),
            'boletas_aprobadas' => $boletas->where('estado', 'aprobada')->count(),
            'total_colaboradores' => $porColaborador->count(),
            'total_monto' => ConsumoComidaFila::centavosATexto($porColaborador->sum('centavos')),
        ];
    }

    /**
     * Resumen del descuento por consumo de comida en el ciclo: suma de las
     * aplicaciones vigentes por colaborador y si ya están en la boleta.
     *
     * @return array<string, mixed>
     */
    public function resumenCiclo(Empresa $empresa, CicloRemunerativo $ciclo): array
    {
        $this->verificarCiclo($empresa, $ciclo);

        $aplicadas = ConsumoComidaAplicacion::where('ciclo_id', $ciclo->id)
            ->where('estado', ConsumoComidaAplicacion::ESTADO_APLICADA)
            ->with(['colaborador:id,nombres,apellidos,numero_documento', 'boleta:id,version,estado'])
            ->get();
        $boletas = $this->boletasVigentes($ciclo, $aplicadas->pluck('colaborador_id')->unique());

        $colaboradores = $aplicadas->groupBy('colaborador_id')->map(function (Collection $grupo, int $colaboradorId) use ($boletas) {
            $colaborador = $grupo->first()->colaborador;
            $pendiente = $grupo->contains(fn (ConsumoComidaAplicacion $a) => $a->boleta_id === null);
            $boleta = $boletas->get($colaboradorId);

            return [
                'colaborador_id' => $colaboradorId,
                'documento' => $colaborador?->numero_documento,
                'nombre' => $colaborador ? trim("{$colaborador->nombres} {$colaborador->apellidos}") : null,
                'consumos' => $grupo->sum('consumos'),
                'monto' => ConsumoComidaFila::centavosATexto($grupo->sum(fn ($a) => ConsumoComidaFila::textoACentavos((string) $a->monto))),
                'importaciones' => $grupo->pluck('importacion_id')->values(),
                'pendiente_recalculo' => $pendiente,
                'boleta' => $boleta && ! $pendiente ? ['id' => $boleta->id, 'version' => $boleta->version, 'estado' => $boleta->estado] : null,
            ];
        })->values();

        return [
            'total_monto' => ConsumoComidaFila::centavosATexto($aplicadas->sum(fn ($a) => ConsumoComidaFila::textoACentavos((string) $a->monto))),
            'total_colaboradores' => $colaboradores->count(),
            'pendientes_recalculo' => ConsumoComidaAplicacion::where('ciclo_id', $ciclo->id)->pendientesDeRecalculo()->distinct()->count('colaborador_id'),
            'concepto_configurado' => $this->conceptoDisponible() !== null,
            'ciclo_modificable' => $this->cicloEsModificable($ciclo),
            'colaboradores' => $colaboradores,
        ];
    }

    /** @return Collection<int, ConsumoComidaDetalle> */
    public function consumosDeColaborador(Empresa $empresa, CicloRemunerativo $ciclo, int $colaboradorId): Collection
    {
        $this->verificarCiclo($empresa, $ciclo);

        return ConsumoComidaDetalle::query()
            ->where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaboradorId)
            ->whereHas('importacion', fn ($q) => $q->where('ciclo_id', $ciclo->id)->where('estado', ConsumoComidaImportacion::ESTADO_APLICADA))
            ->orderBy('fecha_consumo')->orderBy('id')
            ->get();
    }

    public function verificarImportacion(Empresa $empresa, ConsumoComidaImportacion $importacion): void
    {
        if ((int) $importacion->empresa_id !== (int) $empresa->id) {
            throw new AuthorizationException('La importación no pertenece a la empresa autorizada.');
        }
    }

    /**
     * Recalcula el resultado de cada fila con los datos actuales y actualiza
     * los totales y el estado de la importación (solo validada/con_errores).
     */
    private function evaluar(ConsumoComidaImportacion $importacion): void
    {
        if (! in_array($importacion->estado, [ConsumoComidaImportacion::ESTADO_VALIDADA, ConsumoComidaImportacion::ESTADO_CON_ERRORES], true)) {
            return;
        }

        $ciclo = CicloRemunerativo::findOrFail($importacion->ciclo_id);
        $empresa = Empresa::findOrFail($importacion->empresa_id);
        $inicio = $ciclo->fecha_inicio->toDateString();
        $fin = $ciclo->fecha_fin->toDateString();
        $detalles = $importacion->detalles()->orderBy('fila')->get();
        $documentos = $detalles->pluck('documento')->filter()->unique()->values();

        $elegiblesPorDocumento = $this->boletas->colaboradoresElegibles($empresa, $inicio, $fin)
            ->groupBy(fn (Colaborador $c) => trim((string) $c->numero_documento));
        $documentosEnEmpresa = Colaborador::where('empresa_id', $empresa->id)->whereIn('numero_documento', $documentos)
            ->pluck('numero_documento')->map(fn ($d) => trim((string) $d))->flip();

        $referencias = $detalles->pluck('referencia')->filter();
        $referenciasAplicadas = ConsumoComidaDetalle::where('empresa_id', $empresa->id)
            ->where('sistema_origen', $importacion->sistema_origen)
            ->whereIn('referencia_activa', $referencias)
            ->where('importacion_id', '!=', $importacion->id)
            ->pluck('importacion_id', 'referencia_activa');
        $referenciasEnArchivo = $referencias->countBy();

        $clave = fn (ConsumoComidaDetalle $d) => $d->documento && $d->fecha_consumo && $d->concepto && $d->monto !== null
            ? ConsumoComidaFila::claveDuplicado($d->documento, $d->fecha_consumo->format('Y-m-d'), $d->concepto, ConsumoComidaFila::textoACentavos((string) $d->monto))
            : null;
        $clavesEnArchivo = $detalles->filter(fn ($d) => $d->referencia === null)->map($clave)->filter()->countBy();
        $clavesAplicadas = ConsumoComidaDetalle::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('documento', $documentos)
            ->where('importacion_id', '!=', $importacion->id)
            ->whereHas('importacion', fn ($q) => $q->where('estado', ConsumoComidaImportacion::ESTADO_APLICADA))
            ->get()
            ->mapWithKeys(fn (ConsumoComidaDetalle $d) => [$clave($d) => $d->importacion_id]);

        foreach ($detalles as $detalle) {
            $errores = $detalle->errores_formato ?? [];
            $advertencias = [];
            $colaborador = null;

            if ($detalle->documento !== null) {
                $coincidencias = $elegiblesPorDocumento->get($detalle->documento, collect());
                if ($coincidencias->count() === 1) {
                    $colaborador = $coincidencias->first();
                } elseif ($coincidencias->count() > 1) {
                    $errores[] = 'El documento coincide con más de un colaborador del ciclo; no se puede asignar el consumo.';
                } elseif ($documentosEnEmpresa->has($detalle->documento)) {
                    $errores[] = 'El colaborador no tiene vínculo laboral vigente en el período del ciclo.';
                } else {
                    $errores[] = 'No existe un colaborador con este documento en la empresa.';
                }
            }
            if ($detalle->fecha_consumo && ($detalle->fecha_consumo->format('Y-m-d') < $inicio || $detalle->fecha_consumo->format('Y-m-d') > $fin)) {
                $errores[] = "La fecha de consumo está fuera del período del ciclo ({$inicio} a {$fin}).";
            }
            if ($detalle->referencia !== null) {
                if (($referenciasEnArchivo[$detalle->referencia] ?? 0) > 1) {
                    $errores[] = 'La referencia está repetida dentro del archivo.';
                }
                if ($referenciasAplicadas->has($detalle->referencia)) {
                    $errores[] = "La referencia ya fue aplicada en la importación #{$referenciasAplicadas[$detalle->referencia]}.";
                }
            } elseif (($claveFila = $clave($detalle)) !== null) {
                if (($clavesEnArchivo[$claveFila] ?? 0) > 1) {
                    $advertencias[] = 'Posible duplicado: otra fila del archivo tiene el mismo documento, fecha, concepto y monto.';
                }
                if ($clavesAplicadas->has($claveFila)) {
                    $advertencias[] = "Posible duplicado de un consumo ya aplicado en la importación #{$clavesAplicadas[$claveFila]}.";
                }
            }

            $estado = match (true) {
                $errores !== [] => ConsumoComidaDetalle::ESTADO_ERROR,
                $advertencias !== [] => ConsumoComidaDetalle::ESTADO_POSIBLE_DUPLICADO,
                default => ConsumoComidaDetalle::ESTADO_VALIDO,
            };
            $detalle->update([
                'colaborador_id' => $colaborador?->id,
                'colaborador_nombre_snapshot' => $colaborador ? trim("{$colaborador->nombres} {$colaborador->apellidos}") : null,
                'estado' => $estado,
                'errores' => [...$errores, ...$advertencias],
            ]);
        }

        $erroresGenerales = [];
        $mismoContenido = ConsumoComidaImportacion::where('empresa_id', $empresa->id)
            ->where('ciclo_id', $ciclo->id)
            ->where('id', '!=', $importacion->id)
            ->where('estado', ConsumoComidaImportacion::ESTADO_APLICADA)
            ->where(fn ($q) => $q->where('archivo_hash', $importacion->archivo_hash)->orWhere('contenido_hash', $importacion->contenido_hash))
            ->value('id');
        if ($mismoContenido) {
            $erroresGenerales[] = "Este archivo (o el mismo contenido con otro nombre) ya fue aplicado en la importación #{$mismoContenido} de este ciclo.";
        }

        $validos = $detalles->where('estado', '!=', ConsumoComidaDetalle::ESTADO_ERROR);
        $conError = $detalles->where('estado', ConsumoComidaDetalle::ESTADO_ERROR)->count();
        $importacion->update([
            'estado' => $conError > 0 || $erroresGenerales !== [] ? ConsumoComidaImportacion::ESTADO_CON_ERRORES : ConsumoComidaImportacion::ESTADO_VALIDADA,
            'errores_generales' => $erroresGenerales,
            'filas_con_error' => $conError,
            'filas_posible_duplicado' => $detalles->where('estado', ConsumoComidaDetalle::ESTADO_POSIBLE_DUPLICADO)->whereNull('duplicado_admitido_at')->count(),
            'colaboradores_total' => $validos->pluck('colaborador_id')->filter()->unique()->count(),
            'monto_total' => ConsumoComidaFila::centavosATexto($validos->sum(fn ($d) => $d->monto !== null ? ConsumoComidaFila::textoACentavos((string) $d->monto) : 0)),
        ]);
    }

    /** @return Collection<int, array{centavos: int, consumos: int}> */
    private function totalesPorColaborador(ConsumoComidaImportacion $importacion): Collection
    {
        return $importacion->detalles()
            ->where('estado', '!=', ConsumoComidaDetalle::ESTADO_ERROR)
            ->whereNotNull('colaborador_id')
            ->get()
            ->groupBy('colaborador_id')
            ->map(fn (Collection $grupo) => [
                'centavos' => $grupo->sum(fn (ConsumoComidaDetalle $d) => ConsumoComidaFila::textoACentavos((string) $d->monto)),
                'consumos' => $grupo->count(),
            ]);
    }

    /** @return Collection<int, Boleta> */
    private function boletasVigentes(CicloRemunerativo $ciclo, Collection $colaboradorIds): Collection
    {
        return Boleta::where('ciclo_id', $ciclo->id)->whereIn('colaborador_id', $colaboradorIds)
            ->where('es_version_vigente', true)->get()->keyBy('colaborador_id');
    }

    /**
     * Una boleta pagada no se puede modificar. Una aprobada solo con
     * confirmación explícita: al recalcular vuelve a "calculada" y debe
     * aprobarse otra vez (nunca se reabre en silencio).
     */
    private function verificarBoletas(CicloRemunerativo $ciclo, Collection $colaboradorIds, bool $confirmarAprobadas): void
    {
        $boletas = $this->boletasVigentes($ciclo, $colaboradorIds);
        if ($boletas->contains('estado', 'pagada')) {
            throw ValidationException::withMessages(['boletas' => 'Hay boletas pagadas entre los colaboradores afectados; no se pueden modificar sus descuentos.']);
        }
        $aprobadas = $boletas->where('estado', 'aprobada')->count();
        if ($aprobadas > 0 && ! $confirmarAprobadas) {
            throw ValidationException::withMessages(['confirmar_boletas_aprobadas' => "{$aprobadas} boleta(s) afectada(s) ya están aprobadas: al recalcular volverán a \"calculada\" y deberán aprobarse de nuevo. Confirma para continuar."]);
        }
    }

    /** @return list<string> */
    private function bloqueos(ConsumoComidaImportacion $importacion, CicloRemunerativo $ciclo, Collection $boletas): array
    {
        $bloqueos = [];
        if ($importacion->estado !== ConsumoComidaImportacion::ESTADO_VALIDADA) {
            $bloqueos[] = $importacion->estado === ConsumoComidaImportacion::ESTADO_CON_ERRORES
                ? 'La importación tiene errores: corrige el archivo y vuelve a subirlo.'
                : 'La importación ya no está pendiente de confirmar.';
        }
        if ($importacion->filas_posible_duplicado > 0) {
            $bloqueos[] = "Hay {$importacion->filas_posible_duplicado} posible(s) duplicado(s) sin revisar.";
        }
        if (! $this->cicloEsModificable($ciclo)) {
            $bloqueos[] = 'El ciclo está cerrado, pagado o calculándose; no admite cambios en sus descuentos.';
        }
        if ($this->conceptoDisponible() === null) {
            $bloqueos[] = 'El concepto "Adelanto 2" (DESCUENTO_CONSUMO_COMIDA) no está configurado como egreso activo. Configúralo antes de aplicar.';
        }
        if ($boletas->contains('estado', 'pagada')) {
            $bloqueos[] = 'Hay boletas pagadas entre los colaboradores afectados.';
        }

        return $bloqueos;
    }

    private function verificarCiclo(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ((int) $ciclo->empresa_id !== (int) $empresa->id) {
            throw new AuthorizationException('El ciclo no pertenece a la empresa autorizada.');
        }
    }

    private function cicloEsModificable(CicloRemunerativo $ciclo): bool
    {
        return ! in_array($ciclo->estado, ['cerrado', 'pagado'], true) && $ciclo->calculo_estado !== 'en_proceso';
    }

    private function verificarCicloModificable(CicloRemunerativo $ciclo): void
    {
        if (in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            throw ValidationException::withMessages(['ciclo' => 'El ciclo está cerrado o pagado: no se pueden aplicar ni anular descuentos. Reábrelo si corresponde.']);
        }
        // El cálculo en segundo plano lee los conceptos del período: cambiarlos
        // a mitad de camino dejaría boletas con datos mezclados.
        if ($ciclo->calculo_estado === 'en_proceso') {
            throw ValidationException::withMessages(['ciclo' => 'El ciclo se está calculando; espera a que termine para aplicar o anular descuentos.']);
        }
    }

    private function conceptoDisponible(): ?ConceptoRemuneracion
    {
        return ConceptoRemuneracion::where('codigo', self::CODIGO_CONCEPTO)->where('activo', true)->where('tipo', 'egreso')->first();
    }

    private function conceptoConfigurado(): ConceptoRemuneracion
    {
        return $this->conceptoDisponible() ?? throw ValidationException::withMessages([
            'concepto' => 'El concepto "Adelanto 2" (DESCUENTO_CONSUMO_COMIDA) no está configurado como egreso activo. Configúralo antes de aplicar.',
        ]);
    }

    /** Mismo mecanismo que NotificarCambioAsistenciaCiclo: requiere_recalculo + motivo acumulado. */
    private function marcarRecalculo(CicloRemunerativo $ciclo, string $motivo): void
    {
        if (! in_array($ciclo->estado, ['calculado', 'reabierto'], true)) {
            return; // 'abierto': todavía no hay cálculo que invalidar
        }
        $ciclo->update([
            'requiere_recalculo' => true,
            'recalculo_motivo' => trim(($ciclo->recalculo_motivo ? $ciclo->recalculo_motivo."\n" : '').$motivo),
            'recalculo_detectado_at' => $ciclo->recalculo_detectado_at ?? now(),
        ]);
    }

    /** @param list<array<string, mixed>> $filas */
    private function huellaContenido(array $filas, string $sistemaOrigen): string
    {
        $lineas = array_map(fn (array $fila) => implode('|', [
            $fila['documento'] ?? json_encode($fila['valores']['documento'] ?? null),
            $fila['fecha_consumo'] ?? json_encode($fila['valores']['fecha_consumo'] ?? null),
            mb_strtolower(trim((string) ($fila['concepto'] ?? json_encode($fila['valores']['concepto'] ?? null)))),
            $fila['monto_centavos'] ?? json_encode($fila['valores']['monto'] ?? null),
            $fila['referencia'] ?? '',
        ]), $filas);
        sort($lineas);

        return hash('sha256', $sistemaOrigen."\n".implode("\n", $lineas));
    }

    /** @param array<string, mixed>|null $datos */
    private function registrarEvento(ConsumoComidaImportacion $importacion, ?int $usuarioId, string $accion, ?string $motivo, ?array $datos = null): void
    {
        ConsumoComidaEvento::create([
            'importacion_id' => $importacion->id,
            'empresa_id' => $importacion->empresa_id,
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'motivo' => $motivo,
            'datos' => $datos,
        ]);
    }
}
