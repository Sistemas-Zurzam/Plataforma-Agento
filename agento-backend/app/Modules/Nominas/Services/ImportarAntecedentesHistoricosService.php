<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\ClasificadorAntecedenteHistorico;
use App\Modules\Nominas\Infrastructure\AntecedenteHistoricoXlsxReader;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalleCorreccion;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orquesta el Incremento 2 (lector de Excel) — ver el plan aprobado en
 * DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md. Puebla
 * `nomina_importacion_historica_detalles` a partir del Excel real,
 * clasifica cada fila, y expone las únicas cuatro formas de fijar
 * `clasificacion`/`estado_validacion` de una fila: la re-ejecución
 * automática del clasificador (dentro de `importar()`/`corregir()`),
 * `confirmarCtsDepositada()`, `confirmarGratificacionPagada()` y
 * `marcarIgnorado()`. Nunca escribe en las tablas definitivas — eso sigue
 * siendo responsabilidad exclusiva de `AplicarImportacionHistoricaService`.
 */
class ImportarAntecedentesHistoricosService
{
    /**
     * Lista blanca estricta — corrección final del propietario: el
     * corrector genérico corrige datos, nunca promueve una fila observada a
     * aplicable. `clasificacion` y `estado_validacion` están deliberadamente
     * fuera de esta lista.
     */
    private const CAMPOS_CORREGIBLES = [
        'tipo_documento_normalizado', 'numero_documento_normalizado', 'colaborador_id',
        'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'fecha_pago_deposito', 'fecha_corte',
        'importe', 'dias_cantidad',
    ];

    public function __construct(
        private readonly AntecedenteHistoricoXlsxReader $lector,
        private readonly ClasificadorAntecedenteHistorico $clasificador,
    ) {}

    public function importar(Empresa $empresa, UploadedFile $archivo, string $fechaCorte, int $usuarioId): NominaImportacionHistorica
    {
        $ruta = $archivo->getRealPath();
        $hash = hash_file('sha256', $ruta);
        $filas = $this->lector->leer($ruta);

        $nombresValidos = $this->nombresValidosDeEmpresa($empresa);

        return DB::transaction(function () use ($empresa, $archivo, $fechaCorte, $usuarioId, $hash, $filas, $nombresValidos) {
            $importacion = NominaImportacionHistorica::create([
                'empresa_id' => $empresa->id,
                'archivo_nombre_original' => $archivo->getClientOriginalName(),
                'archivo_hash' => $hash,
                'fecha_corte' => $fechaCorte,
                'estado' => 'borrador',
                'cargado_por' => $usuarioId,
                'cargado_at' => now(),
            ]);

            $contadores = [
                'filas_totales' => 0, 'filas_validas' => 0, 'filas_observadas' => 0,
                'filas_con_errores' => 0, 'filas_ignoradas' => 0, 'filas_otra_empresa' => 0,
            ];

            foreach ($filas as $fila) {
                $empresaInformada = $fila['empresa_informada'] !== null ? mb_strtoupper(trim($fila['empresa_informada'])) : null;

                // Corrección de privacidad: una fila de otra empresa del grupo
                // NUNCA se persiste como detalle — ni su nombre, ni su
                // documento, ni datos_originales quedan dentro de este lote.
                if ($empresaInformada !== null && $nombresValidos !== [] && ! in_array($empresaInformada, $nombresValidos, true)) {
                    $contadores['filas_otra_empresa']++;

                    continue;
                }

                $contadores['filas_totales']++;
                $this->procesarFila($importacion, $empresa, $fila, $fechaCorte, $contadores);
            }

            $importacion->update([
                ...$contadores,
                'estado' => 'validado',
                'validado_por' => $usuarioId,
                'validado_at' => now(),
            ]);

            return $importacion->fresh();
        });
    }

    /** @param  array<string, int>  $contadores */
    private function procesarFila(NominaImportacionHistorica $importacion, Empresa $empresa, array $fila, string $fechaCorte, array &$contadores): void
    {
        $esProvision = (bool) ($fila['es_provision'] ?? false);

        $documento = $this->resolverColaborador($empresa, $fila['numero_documento_normalizado']);

        $errores = [];
        if ($fila['importe'] === null) {
            $errores[] = 'Falta el importe (columna MONTO) o no es numérico.';
        }

        if ($esProvision) {
            $clasificacion = 'no_aplicable';
            $tipoAntecedente = null;
            $advertencias = [];
        } else {
            $resultado = $this->clasificador->clasificar(
                $fila['tipo_calculo_original'] ?? '',
                $fila['nombre_concepto_original'] ?? '',
                $fila['estado_excel'] ?? '',
                $this->esLocador($fila['regimen_informado'] ?? null),
            );
            $clasificacion = $resultado['clasificacion'];
            $tipoAntecedente = $resultado['tipo_antecedente'];
            $advertencias = $resultado['advertencias'];
        }

        if ($errores !== []) {
            $clasificacion = 'error';
        }

        $advertencias = [...$advertencias, ...$documento['advertencias']];
        $estadoValidacion = $this->estadoValidacionParaClasificacion($clasificacion);

        NominaImportacionHistoricaDetalle::create([
            'importacion_id' => $importacion->id,
            'empresa_id' => $empresa->id,
            'hoja_nombre' => $fila['hoja_nombre'],
            'fila_numero' => $fila['fila_numero'],
            'datos_originales' => $fila['datos_originales'],
            'tipo_documento_normalizado' => $documento['tipo_documento'],
            'numero_documento_normalizado' => $fila['numero_documento_normalizado'],
            'colaborador_nombre_original' => $fila['colaborador_nombre_original'],
            'colaborador_id' => $documento['colaborador_id'],
            'fecha_ingreso_vinculo' => $fila['fecha_ingreso_vinculo'],
            'fecha_fin_vinculo' => $fila['fecha_fin_vinculo'],
            'empresa_informada' => $fila['empresa_informada'],
            'regimen_informado' => $fila['regimen_informado'],
            'tipo_calculo_original' => $fila['tipo_calculo_original'],
            'tipo_antecedente' => $tipoAntecedente,
            'clasificacion' => $clasificacion,
            'codigo_concepto_original' => $fila['codigo_concepto_original'],
            'nombre_concepto_original' => $fila['nombre_concepto_original'],
            'anio' => $fila['anio'],
            'mes' => $fila['mes'],
            'fecha_periodo_inicio' => $fila['fecha_periodo_inicio'],
            'fecha_periodo_fin' => $fila['fecha_periodo_fin'],
            'fecha_pago_deposito' => $fila['fecha_pago_deposito'],
            'fecha_corte' => $fila['fecha_corte'] ?? $fechaCorte,
            'importe' => $fila['importe'],
            'dias_cantidad' => $fila['dias_cantidad'],
            'estado_excel' => $fila['estado_excel'],
            'estado_validacion' => $estadoValidacion,
            'errores' => $errores,
            'advertencias' => $advertencias,
            'fingerprint_negocio' => $this->calcularFingerprint(
                $empresa->id, $fila['numero_documento_normalizado'], $tipoAntecedente, $fila['anio'], $fila['mes'], $fila['nombre_concepto_original'],
            ),
        ]);

        match ($clasificacion) {
            'error' => $contadores['filas_con_errores']++,
            'no_aplicable' => $contadores['filas_ignoradas']++,
            'observado' => $contadores['filas_observadas']++,
            'aplicable' => $contadores['filas_validas']++,
            default => null,
        };
    }

    public function aprobar(Empresa $empresa, NominaImportacionHistorica $importacion, int $usuarioId): NominaImportacionHistorica
    {
        $this->verificarPertenenciaLote($empresa, $importacion);

        return DB::transaction(function () use ($importacion, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();

            if ($importacion->estado !== 'validado') {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede aprobar un lote en estado "validado".',
                ]);
            }

            $tieneErrores = NominaImportacionHistoricaDetalle::where('importacion_id', $importacion->id)
                ->where('estado_validacion', 'error')->exists();
            if ($tieneErrores) {
                throw ValidationException::withMessages([
                    'detalles' => 'El lote tiene filas con errores — corrígelas o descártalas antes de aprobar.',
                ]);
            }

            $importacion->update(['estado' => 'aprobado', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);

            return $importacion->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    public function corregir(
        Empresa $empresa, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        array $cambios, string $motivo, int $usuarioId,
    ): NominaImportacionHistoricaDetalle {
        $this->verificarPertenenciaDetalle($empresa, $importacion, $detalle);

        $clavesInvalidas = array_diff(array_keys($cambios), self::CAMPOS_CORREGIBLES);
        if ($clavesInvalidas !== []) {
            throw ValidationException::withMessages([
                'cambios' => 'Campos no corregibles: '.implode(', ', $clavesInvalidas).'.',
            ]);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'El motivo es obligatorio.']);
        }
        if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se pueden corregir filas mientras el lote esté en borrador o validado.',
            ]);
        }

        return DB::transaction(function () use ($empresa, $importacion, $detalle, $cambios, $motivo, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se pueden corregir filas mientras el lote esté en borrador o validado.',
                ]);
            }

            $detalle = NominaImportacionHistoricaDetalle::whereKey($detalle->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('colaborador_id', $cambios) && $cambios['colaborador_id'] !== null) {
                $colaborador = Colaborador::withTrashed()->find($cambios['colaborador_id']);
                if (! $colaborador || $colaborador->empresa_id !== $empresa->id) {
                    throw ValidationException::withMessages([
                        'colaborador_id' => 'El colaborador no existe o no pertenece a la empresa de este lote.',
                    ]);
                }
            }

            foreach ($cambios as $campo => $valorNuevo) {
                $valorAnterior = $detalle->getRawOriginal($campo);
                if ((string) $valorAnterior === (string) $valorNuevo) {
                    continue;
                }

                $detalle->{$campo} = $valorNuevo;
                NominaImportacionHistoricaDetalleCorreccion::create([
                    'detalle_id' => $detalle->id,
                    'campo' => $campo,
                    'valor_anterior' => $valorAnterior !== null ? (string) $valorAnterior : null,
                    'valor_nuevo' => $valorNuevo !== null ? (string) $valorNuevo : null,
                    'motivo' => $motivo,
                    'corregido_por' => $usuarioId,
                    'corregido_at' => now(),
                ]);
            }
            $detalle->save();

            $this->reclasificar($empresa, $detalle);

            return $detalle->fresh();
        });
    }

    public function confirmarCtsDepositada(
        Empresa $empresa, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        string $referenciaDeposito, Carbon $fechaDeposito, string $motivo, int $usuarioId,
    ): NominaImportacionHistoricaDetalle {
        $this->verificarPertenenciaDetalle($empresa, $importacion, $detalle);

        $colaborador = $detalle->colaborador_id ? Colaborador::withTrashed()->find($detalle->colaborador_id) : null;

        $errores = [];
        if (mb_strtoupper(trim((string) $detalle->tipo_calculo_original)) !== 'CTS'
            || mb_strtoupper(trim((string) $detalle->nombre_concepto_original)) !== 'CTS') {
            $errores[] = 'El detalle no corresponde a un concepto CTS bajo tipo de cálculo CTS.';
        }
        if (mb_strtoupper(trim((string) $detalle->estado_excel)) !== 'CANCELADO') {
            $errores[] = 'El estado original de la fila no era "Cancelado".';
        }
        if (trim($referenciaDeposito) === '') {
            $errores[] = 'La referencia de depósito es obligatoria.';
        }
        if (! $colaborador) {
            $errores[] = 'La fila no tiene un colaborador y vínculo resueltos.';
        }
        if ($detalle->importe === null || (float) $detalle->importe <= 0) {
            $errores[] = 'El importe debe ser mayor a cero.';
        }
        if (trim($motivo) === '') {
            $errores[] = 'El motivo/sustento es obligatorio.';
        }
        if ($colaborador) {
            // La CTS solo se calcula/deposita en mayo o noviembre: `mes` es
            // directamente el mes en que se depositó (mismo criterio que
            // confirmarGratificacionPagada()), nunca un mes dentro de un
            // periodo más amplio.
            $mesEsperado = $detalle->mes !== null ? (((int) $detalle->mes <= 6) ? 5 : 11) : null;
            $errores = [...$errores, ...$this->validarFechaConfirmacion($detalle, $importacion, $colaborador, $fechaDeposito, $mesEsperado)];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages(['confirmacion' => $errores]);
        }
        if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede confirmar mientras el lote esté en borrador o validado.',
            ]);
        }

        return DB::transaction(function () use ($importacion, $detalle, $referenciaDeposito, $fechaDeposito, $motivo, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede confirmar mientras el lote esté en borrador o validado.',
                ]);
            }

            $detalle = NominaImportacionHistoricaDetalle::whereKey($detalle->id)->lockForUpdate()->firstOrFail();
            $estadoAnterior = "{$detalle->clasificacion}/{$detalle->estado_validacion}";

            $detalle->update([
                'referencia_pago_confirmada' => $referenciaDeposito,
                'fecha_pago_confirmada' => $fechaDeposito->toDateString(),
                'tipo_antecedente' => 'cts_depositada',
                'clasificacion' => 'aplicable',
                'estado_validacion' => 'valido',
            ]);

            NominaImportacionHistoricaDetalleCorreccion::create([
                'detalle_id' => $detalle->id,
                'campo' => 'confirmacion_cts_depositada',
                'valor_anterior' => $estadoAnterior,
                'valor_nuevo' => "aplicable/valido (referencia: {$referenciaDeposito}, fecha: {$fechaDeposito->toDateString()})",
                'motivo' => $motivo,
                'corregido_por' => $usuarioId,
                'corregido_at' => now(),
            ]);

            return $detalle->fresh();
        });
    }

    public function confirmarGratificacionPagada(
        Empresa $empresa, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        Carbon $fechaPago, ?string $referenciaPago, string $motivo, int $usuarioId,
    ): NominaImportacionHistoricaDetalle {
        $this->verificarPertenenciaDetalle($empresa, $importacion, $detalle);

        $colaborador = $detalle->colaborador_id ? Colaborador::withTrashed()->find($detalle->colaborador_id) : null;

        $errores = [];
        if (mb_strtoupper(trim((string) $detalle->nombre_concepto_original)) !== 'GRATIFICACION ORDINARIA') {
            $errores[] = 'El detalle no corresponde al concepto GRATIFICACION ORDINARIA.';
        }
        if (mb_strtoupper(trim((string) $detalle->tipo_calculo_original)) !== 'PLANILLA') {
            $errores[] = 'El detalle no corresponde a tipo de cálculo PLANILLA.';
        }
        if (mb_strtoupper(trim((string) $detalle->estado_excel)) !== 'CANCELADO') {
            $errores[] = 'El estado original de la fila no era "Cancelado".';
        }
        if (! in_array((int) $detalle->mes, [7, 12], true)) {
            $errores[] = 'El mes debe ser julio o diciembre.';
        }
        if ($detalle->importe === null || (float) $detalle->importe <= 0) {
            $errores[] = 'El importe debe ser mayor a cero.';
        }
        if (! $colaborador) {
            $errores[] = 'La fila no tiene un colaborador resuelto.';
        } elseif ($detalle->fecha_ingreso_vinculo && ! $colaborador->fecha_ingreso->isSameDay($detalle->fecha_ingreso_vinculo)) {
            $errores[] = 'La fecha de ingreso del vínculo no coincide con la registrada del colaborador.';
        }
        if (trim($motivo) === '') {
            $errores[] = 'El motivo/sustento es obligatorio.';
        }
        if ($colaborador) {
            $mesEsperado = in_array((int) $detalle->mes, [7, 12], true) ? (int) $detalle->mes : null;
            $errores = [...$errores, ...$this->validarFechaConfirmacion($detalle, $importacion, $colaborador, $fechaPago, $mesEsperado)];
        }
        if ($errores !== []) {
            throw ValidationException::withMessages(['confirmacion' => $errores]);
        }
        if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede confirmar mientras el lote esté en borrador o validado.',
            ]);
        }

        return DB::transaction(function () use ($importacion, $detalle, $fechaPago, $referenciaPago, $motivo, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede confirmar mientras el lote esté en borrador o validado.',
                ]);
            }

            $detalle = NominaImportacionHistoricaDetalle::whereKey($detalle->id)->lockForUpdate()->firstOrFail();
            $estadoAnterior = "{$detalle->clasificacion}/{$detalle->estado_validacion}";

            $detalle->update([
                'referencia_pago_confirmada' => $referenciaPago,
                'fecha_pago_confirmada' => $fechaPago->toDateString(),
                'tipo_antecedente' => 'gratificacion_pagada',
                'clasificacion' => 'aplicable',
                'estado_validacion' => 'valido',
            ]);

            NominaImportacionHistoricaDetalleCorreccion::create([
                'detalle_id' => $detalle->id,
                'campo' => 'confirmacion_gratificacion_pagada',
                'valor_anterior' => $estadoAnterior,
                'valor_nuevo' => 'aplicable/valido (fecha: '.$fechaPago->toDateString().($referenciaPago ? ", referencia: {$referenciaPago}" : '').')',
                'motivo' => $motivo,
                'corregido_por' => $usuarioId,
                'corregido_at' => now(),
            ]);

            return $detalle->fresh();
        });
    }

    public function marcarIgnorado(
        Empresa $empresa, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        string $motivo, int $usuarioId,
    ): NominaImportacionHistoricaDetalle {
        $this->verificarPertenenciaDetalle($empresa, $importacion, $detalle);

        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'El motivo es obligatorio.']);
        }
        if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'Solo se puede marcar ignorada una fila mientras el lote esté en borrador o validado.',
            ]);
        }

        return DB::transaction(function () use ($importacion, $detalle, $motivo, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();
            if (! in_array($importacion->estado, ['borrador', 'validado'], true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede marcar ignorada una fila mientras el lote esté en borrador o validado.',
                ]);
            }

            $detalle = NominaImportacionHistoricaDetalle::whereKey($detalle->id)->lockForUpdate()->firstOrFail();
            $estadoAnterior = "{$detalle->clasificacion}/{$detalle->estado_validacion}";

            $detalle->update(['clasificacion' => 'no_aplicable', 'estado_validacion' => 'ignorado']);

            NominaImportacionHistoricaDetalleCorreccion::create([
                'detalle_id' => $detalle->id,
                'campo' => 'marcado_ignorado',
                'valor_anterior' => $estadoAnterior,
                'valor_nuevo' => 'no_aplicable/ignorado',
                'motivo' => $motivo,
                'corregido_por' => $usuarioId,
                'corregido_at' => now(),
            ]);

            return $detalle->fresh();
        });
    }

    /**
     * Reglas de fecha comunes a las confirmaciones manuales de CTS y de
     * gratificación — endurecimiento pedido explícitamente por el
     * propietario: la fecha confirmada no puede ser anterior al ingreso del
     * colaborador, ni posterior al corte del lote, ni posterior a su cese,
     * ni futura, ni caer en un mes/año distinto al que le correspondería
     * según el periodo declarado de la fila (cuando ese periodo es
     * determinable a partir de `mes`/`anio`).
     *
     * @return array<int, string>
     */
    private function validarFechaConfirmacion(
        NominaImportacionHistoricaDetalle $detalle, NominaImportacionHistorica $importacion,
        Colaborador $colaborador, Carbon $fecha, ?int $mesEsperado,
    ): array {
        $errores = [];

        if ($colaborador->fecha_ingreso && $fecha->lt($colaborador->fecha_ingreso)) {
            $errores[] = 'La fecha es anterior a la fecha de ingreso del colaborador.';
        }
        if ($importacion->fecha_corte && $fecha->gt($importacion->fecha_corte)) {
            $errores[] = 'La fecha es posterior a la fecha de corte del lote.';
        }
        if ($colaborador->fecha_cese && $fecha->gt($colaborador->fecha_cese)) {
            $errores[] = 'La fecha es posterior a la fecha de cese del colaborador.';
        }
        if ($fecha->gt(Carbon::today())) {
            $errores[] = 'La fecha no puede ser futura.';
        }
        if ($mesEsperado !== null && $detalle->anio !== null
            && ((int) $fecha->month !== $mesEsperado || (int) $fecha->year !== (int) $detalle->anio)) {
            $errores[] = 'La fecha no corresponde al mes/año esperado según el periodo declarado de la fila.';
        }

        return $errores;
    }

    private function reclasificar(Empresa $empresa, NominaImportacionHistoricaDetalle $detalle): void
    {
        $errores = [];
        $advertencias = [];

        if ($detalle->importe === null) {
            $errores[] = 'Falta el importe (columna MONTO) o no es numérico.';
        }

        $resultado = $this->clasificador->clasificar(
            $detalle->tipo_calculo_original ?? '',
            $detalle->nombre_concepto_original ?? '',
            $detalle->estado_excel ?? '',
            $this->esLocador($detalle->regimen_informado),
        );
        $advertencias = [...$advertencias, ...$resultado['advertencias']];
        $clasificacion = $errores !== [] ? 'error' : $resultado['clasificacion'];

        if ($detalle->colaborador_id === null) {
            $advertencias[] = 'Fila sin colaborador vinculado.';
        } else {
            $colaborador = Colaborador::withTrashed()->find($detalle->colaborador_id);
            if ($colaborador && $detalle->fecha_ingreso_vinculo && ! $colaborador->fecha_ingreso->isSameDay($detalle->fecha_ingreso_vinculo)) {
                $advertencias[] = 'Posible recontratación: la fecha de ingreso del vínculo no coincide con la registrada del colaborador.';
            }
        }

        $detalle->update([
            'tipo_antecedente' => $resultado['tipo_antecedente'],
            'clasificacion' => $clasificacion,
            'estado_validacion' => $this->estadoValidacionParaClasificacion($clasificacion),
            'errores' => $errores,
            'advertencias' => $advertencias,
            'fingerprint_negocio' => $this->calcularFingerprint(
                $empresa->id, $detalle->numero_documento_normalizado, $resultado['tipo_antecedente'], $detalle->anio, $detalle->mes, $detalle->nombre_concepto_original,
            ),
        ]);
    }

    private function estadoValidacionParaClasificacion(string $clasificacion): string
    {
        return match ($clasificacion) {
            'error' => 'error',
            'no_aplicable' => 'ignorado',
            'observado' => 'observado',
            'aplicable' => 'valido',
            default => 'pendiente',
        };
    }

    /**
     * @return array{colaborador_id: ?int, tipo_documento: ?string, advertencias: array<int, string>}
     */
    private function resolverColaborador(Empresa $empresa, ?string $numeroDocumento): array
    {
        if ($numeroDocumento === null) {
            return ['colaborador_id' => null, 'tipo_documento' => null, 'advertencias' => ['Fila sin número de documento.']];
        }

        $candidatos = Colaborador::withTrashed()->where('empresa_id', $empresa->id)
            ->where('numero_documento', $numeroDocumento)->get();

        return match (true) {
            $candidatos->count() === 1 => [
                'colaborador_id' => $candidatos->first()->id,
                'tipo_documento' => $candidatos->first()->tipo_documento,
                'advertencias' => [],
            ],
            $candidatos->count() > 1 => [
                'colaborador_id' => null, 'tipo_documento' => null,
                'advertencias' => ["El documento \"{$numeroDocumento}\" coincide con más de un colaborador en la empresa — requiere vinculación manual."],
            ],
            default => [
                'colaborador_id' => null, 'tipo_documento' => null,
                'advertencias' => ["No se encontró ningún colaborador con documento \"{$numeroDocumento}\" en la empresa."],
            ],
        };
    }

    private function esLocador(?string $regimenInformado): bool
    {
        return $regimenInformado !== null && str_contains(mb_strtoupper($regimenInformado), 'RECIB');
    }

    private function calcularFingerprint(int $empresaId, ?string $numeroDocumento, ?string $tipoAntecedente, ?int $anio, ?int $mes, ?string $concepto): string
    {
        return hash('sha256', implode('|', [$empresaId, $numeroDocumento, $tipoAntecedente, $anio, $mes, $concepto]));
    }

    /** @return array<int, string> */
    private function nombresValidosDeEmpresa(Empresa $empresa): array
    {
        return collect([$empresa->nombre_comercial, $empresa->abreviatura])
            ->filter()
            ->map(fn (string $nombre) => mb_strtoupper(trim($nombre)))
            ->values()->all();
    }

    private function verificarPertenenciaLote(Empresa $empresa, NominaImportacionHistorica $importacion): void
    {
        if ($importacion->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El lote de importación no pertenece a la empresa activa.');
        }
    }

    private function verificarPertenenciaDetalle(Empresa $empresa, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle): void
    {
        $this->verificarPertenenciaLote($empresa, $importacion);
        if ($detalle->importacion_id !== $importacion->id) {
            throw new AuthorizationException('El detalle no pertenece a este lote.');
        }
    }
}
