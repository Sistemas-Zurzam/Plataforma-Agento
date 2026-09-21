<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 2 — auditoría de correcciones manuales sobre una fila de
 * staging (`ImportarAntecedentesHistoricosService::corregir()`,
 * `confirmarCtsDepositada()`, `confirmarGratificacionPagada()`,
 * `marcarIgnorado()`). El campo corregido varía por fila (no encaja en
 * columnas fijas de auditoría como `aprobado_por`/`aprobado_at`), de ahí el
 * patrón "changelog": una fila por cada campo efectivamente cambiado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_importacion_historica_detalle_correcciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detalle_id');
            $table->foreign('detalle_id', 'nomina_import_hist_correcciones_detalle_id_fk')
                ->references('id')->on('nomina_importacion_historica_detalles')->cascadeOnDelete();
            $table->string('campo', 60);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->string('motivo', 255);
            $table->foreignId('corregido_por')->nullable();
            $table->foreign('corregido_por', 'nomina_import_hist_correcciones_corregido_por_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('corregido_at');
            $table->timestamps();

            $table->index(['detalle_id', 'corregido_at'], 'nomina_import_hist_correcciones_detalle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_importacion_historica_detalle_correcciones');
    }
};
