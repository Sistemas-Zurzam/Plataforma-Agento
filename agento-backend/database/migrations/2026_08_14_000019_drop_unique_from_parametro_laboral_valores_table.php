<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La restricción única (empresa, definición, régimen, vigencia_desde)
     * impedía registrar dos correcciones el mismo día para la misma
     * combinación (ej. inicializar valores por defecto y corregir uno de
     * inmediato) — un caso de uso legítimo. Se reemplaza por un índice
     * simple; el desempate entre filas con la misma vigencia_desde se
     * resuelve por id (la más reciente insertada gana).
     */
    public function up(): void
    {
        Schema::table('parametro_laboral_valores', function (Blueprint $table) {
            // El índice nuevo debe crearse antes de soltar el único: MySQL
            // exige que exista siempre algún índice cubriendo las columnas
            // de las foreign keys (empresa_id, definicion_id).
            $table->index(
                ['empresa_id', 'definicion_id', 'regimen_laboral', 'vigencia_desde'],
                'parametro_laboral_valores_indice',
            );
            $table->dropUnique('parametro_laboral_valores_unico');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parametro_laboral_valores', function (Blueprint $table) {
            $table->unique(
                ['empresa_id', 'definicion_id', 'regimen_laboral', 'vigencia_desde'],
                'parametro_laboral_valores_unico',
            );
            $table->dropIndex('parametro_laboral_valores_indice');
        });
    }
};
