<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endurecimiento post-Incremento 1: permite cargar antecedentes históricos
 * de un colaborador cuya liquidación de cese YA fue pagada, pero solo
 * cuando el lote lo autoriza EXPLÍCITAMENTE (una decisión tomada al aprobar
 * el lote, nunca un valor por defecto silencioso) — ver
 * AplicarImportacionHistoricaService::verificarColaboradorElegible().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_importaciones_historicas', function (Blueprint $table) {
            $table->boolean('autoriza_cesados_con_liquidacion_pagada')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_importaciones_historicas', function (Blueprint $table) {
            $table->dropColumn('autoriza_cesados_con_liquidacion_pagada');
        });
    }
};
