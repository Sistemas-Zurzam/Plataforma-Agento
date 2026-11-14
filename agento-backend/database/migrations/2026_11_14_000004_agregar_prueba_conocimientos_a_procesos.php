<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prueba de conocimientos del proceso (por familia: siempre la última
 * versión publicada), cuándo se envía automáticamente ("postular" o
 * "filtro_cv": al pasar el filtro de CV) y el puntaje mínimo (0–100) que
 * marca a quien no lo alcanza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seleccion_procesos', function (Blueprint $table) {
            $table->string('conocimientos_familia', 36)->nullable()->after('prueba_nivel_minimo');
            $table->string('conocimientos_momento', 15)->nullable()->after('conocimientos_familia');
            $table->unsignedTinyInteger('conocimientos_puntaje_minimo')->nullable()->after('conocimientos_momento');
        });
    }

    public function down(): void
    {
        Schema::table('seleccion_procesos', function (Blueprint $table) {
            $table->dropColumn(['conocimientos_familia', 'conocimientos_momento', 'conocimientos_puntaje_minimo']);
        });
    }
};
