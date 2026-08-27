<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * colaborador_remuneraciones y boletas usaban cascadeOnDelete() en
 * colaborador_id: un forceDelete() sobre un Colaborador (fuera del flujo
 * normal de cese, que usa soft delete) borraba en cascada todo el
 * historial remunerativo y las boletas de pago, algo que CLAUDE.md prohíbe
 * explícitamente ("no reemplazar indiscriminadamente información
 * histórica"). Se cambia a restrictOnDelete() para forzar una decisión
 * explícita (archivar/anonimizar) en vez de un borrado silencioso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_remuneraciones', function ($table) {
            $table->dropForeign(['colaborador_id']);
        });
        Schema::table('colaborador_remuneraciones', function ($table) {
            $table->foreign('colaborador_id')->references('id')->on('colaboradores')->restrictOnDelete();
        });

        Schema::table('boletas', function ($table) {
            $table->dropForeign(['colaborador_id']);
        });
        Schema::table('boletas', function ($table) {
            $table->foreign('colaborador_id')->references('id')->on('colaboradores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_remuneraciones', function ($table) {
            $table->dropForeign(['colaborador_id']);
        });
        Schema::table('colaborador_remuneraciones', function ($table) {
            $table->foreign('colaborador_id')->references('id')->on('colaboradores')->cascadeOnDelete();
        });

        Schema::table('boletas', function ($table) {
            $table->dropForeign(['colaborador_id']);
        });
        Schema::table('boletas', function ($table) {
            $table->foreign('colaborador_id')->references('id')->on('colaboradores')->cascadeOnDelete();
        });
    }
};
