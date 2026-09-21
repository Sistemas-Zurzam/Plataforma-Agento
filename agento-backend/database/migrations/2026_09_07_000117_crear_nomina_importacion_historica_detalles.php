<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 1 — filas temporales (staging) de un lote de importación
 * histórica, una por cada fila del Excel, antes de convertirse en un
 * antecedente definitivo (beneficio histórico / saldo vacacional / saldo
 * laboral pendiente). Puramente aditiva.
 *
 * `empresa_id` está deliberadamente denormalizado desde
 * `nomina_importaciones_historicas.empresa_id` (en vez de resolverse solo
 * vía `importacion_id`): ningún motor SQL soporta un índice único que cruce
 * dos tablas, y la regla "el mismo antecedente de negocio no se aplica dos
 * veces AUNQUE EL EXCEL VUELVA A IMPORTARSE" exige comparar filas de LOTES
 * distintos (`importacion_id` distinto) para la misma empresa — sin esta
 * columna repetida, ese índice sería imposible de expresar en SQL.
 *
 * Igual que en la tabla de lotes, la idempotencia real usa una columna
 * generada (`fingerprint_negocio_aplicado`) que solo replica el fingerprint
 * cuando la fila ya fue promovida (`estado_validacion = 'aplicado'`) — así
 * una fila observada/errada puede corregirse y reimportarse sin bloqueo.
 *
 * `fingerprint_negocio` lo calculará el importador de Incremento 2 (a
 * partir de documento + tipo de antecedente + periodo + concepto, nunca del
 * nombre del colaborador) — en este incremento la columna existe pero no se
 * llena todavía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_importacion_historica_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->constrained('nomina_importaciones_historicas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();

            $table->string('hoja_nombre', 120);
            $table->unsignedInteger('fila_numero');
            $table->json('datos_originales');

            $table->string('tipo_documento_normalizado', 20)->nullable();
            $table->string('numero_documento_normalizado', 20)->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->restrictOnDelete();

            $table->date('fecha_ingreso_vinculo')->nullable();
            $table->date('fecha_fin_vinculo')->nullable();
            $table->string('empresa_informada', 160)->nullable();
            $table->string('regimen_informado', 60)->nullable();

            // gratificacion_pagada|cts_depositada|saldo_vacacional|vacaciones_gozadas|
            // prestamo_pendiente|adelanto_pendiente|descuento_pendiente|liquidacion_historica|otro
            $table->string('tipo_antecedente', 30);

            $table->string('codigo_concepto_original', 60)->nullable();
            $table->string('nombre_concepto_original', 160)->nullable();
            $table->unsignedSmallInteger('anio')->nullable();
            $table->unsignedTinyInteger('mes')->nullable();
            $table->date('fecha_periodo_inicio')->nullable();
            $table->date('fecha_periodo_fin')->nullable();
            $table->date('fecha_pago_deposito')->nullable();
            $table->date('fecha_corte')->nullable();
            $table->decimal('importe', 12, 2)->nullable();
            $table->decimal('dias_cantidad', 8, 4)->nullable();
            $table->string('estado_excel', 60)->nullable(); // texto tal cual venía en el Excel (ej. "Cancelado")

            $table->string('estado_validacion', 20)->default('pendiente'); // pendiente|valido|observado|error|aplicado
            $table->json('errores')->nullable();
            $table->json('advertencias')->nullable();
            $table->string('fingerprint_negocio', 128)->nullable();

            $table->timestamps();

            $table->string('fingerprint_negocio_aplicado', 128)->nullable()
                ->storedAs("CASE WHEN estado_validacion = 'aplicado' THEN fingerprint_negocio ELSE NULL END");

            $table->unique(['importacion_id', 'hoja_nombre', 'fila_numero'], 'nomina_import_hist_det_lote_fila_unique');
            $table->unique(['empresa_id', 'fingerprint_negocio_aplicado'], 'nomina_import_hist_det_fingerprint_aplicado_unique');
            $table->index(['empresa_id', 'tipo_antecedente'], 'nomina_import_hist_det_empresa_tipo_idx');
            $table->index('colaborador_id', 'nomina_import_hist_det_colaborador_idx');
            $table->index('numero_documento_normalizado', 'nomina_import_hist_det_numero_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_importacion_historica_detalles');
    }
};
