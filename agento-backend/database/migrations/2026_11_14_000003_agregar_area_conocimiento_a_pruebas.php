<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profesión o área de una prueba de conocimientos (ej. "Contabilidad",
 * "Diseño gráfico", "Ventas"): organiza el banco y permite elegir la prueba
 * que corresponde al puesto. Las psicométricas no la usan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seleccion_pruebas', function (Blueprint $table) {
            $table->string('area_conocimiento', 100)->nullable()->after('tipo');
            $table->index('area_conocimiento', 'sel_pruebas_area_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seleccion_pruebas', function (Blueprint $table) {
            $table->dropIndex('sel_pruebas_area_idx');
            $table->dropColumn('area_conocimiento');
        });
    }
};
