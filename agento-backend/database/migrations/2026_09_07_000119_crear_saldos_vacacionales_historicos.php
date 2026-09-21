<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 1 — saldo vacacional consolidado y aprobado a una fecha de
 * corte (ej. 31/07/2026), para no recalcular toda la antigüedad del
 * colaborador desde el Excel fila por fila.
 *
 * A propósito NO se escribe en `vacacion_movimientos` desde este incremento:
 * ese kardex ya lo consume `LiquidacionCeseService::previsualizar()`
 * (informe de diagnóstico, LiquidacionCeseService.php:129-137), y volcarlo
 * automáticamente aquí duplicaría el devengo (el servicio ya suma TODA la
 * antigüedad vía `fecha_ingreso`, así que un `devengo_inicial` positivo se
 * sumaría encima del cálculo por antigüedad, no en su lugar). Cómo
 * conciliar ambas fuentes sin duplicar es una decisión de diseño explícita
 * para Incremento 2 — aquí solo se almacena el dato aprobado.
 *
 * "Vigente" para este incremento significa `estado = 'aprobado'` (no existe
 * columna de versión/es_version_vigente en esta tabla): la columna generada
 * `fecha_corte_aprobada` solo replica `fecha_corte` en ese estado, y el
 * índice único va sobre ella — así solo puede existir UN saldo aprobado por
 * vínculo y fecha de corte, sin bloquear un borrador previo descartado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos_vacacionales_historicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('importacion_detalle_id')->nullable()
                ->constrained('nomina_importacion_historica_detalles')->nullOnDelete();

            $table->date('fecha_ingreso_vinculo');
            $table->date('fecha_fin_vinculo')->nullable();
            $table->date('fecha_corte');

            $table->decimal('dias_devengados', 8, 4)->unsigned()->nullable();
            $table->decimal('dias_gozados', 8, 4)->unsigned()->nullable();
            $table->decimal('dias_pagados', 8, 4)->unsigned()->nullable();
            $table->decimal('dias_pendientes', 8, 4)->unsigned();

            $table->string('estado', 20)->default('borrador'); // borrador|aprobado|anulado
            $table->string('origen', 30)->default('excel_historico');
            $table->text('observaciones')->nullable();

            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion', 255)->nullable();

            $table->timestamps();

            $table->date('fecha_corte_aprobada')->nullable()
                ->storedAs("CASE WHEN estado = 'aprobado' THEN fecha_corte ELSE NULL END");

            $table->unique(
                ['empresa_id', 'colaborador_id', 'fecha_ingreso_vinculo', 'fecha_corte_aprobada'],
                'saldo_vacacional_hist_vinculo_corte_aprobado_unique'
            );
            $table->index('importacion_detalle_id', 'saldo_vacacional_hist_import_detalle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldos_vacacionales_historicos');
    }
};
