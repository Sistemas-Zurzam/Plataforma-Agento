<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Las notas de "modelo Agento insuficiente"/"evidencia parcial" que
     * cargará la migración 000075 son explicaciones completas (para que el
     * administrador entienda EXACTAMENTE qué falta), no etiquetas cortas —
     * superan los 255 caracteres de un `string()`. Se ensancha a TEXT con
     * SQL crudo (MODIFY COLUMN) para no depender de doctrine/dbal, que no
     * está instalado y que exigiría Blueprint::change().
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['tipos_ausencia', 'sunat_mapeos', 'concepto_codigos_plame'] as $tabla) {
                Schema::table($tabla, fn (Blueprint $table) => $table->text('descripcion_sunat')->nullable()->change());
            }
            return;
        }
        DB::statement('ALTER TABLE tipos_ausencia MODIFY COLUMN descripcion_sunat TEXT NULL');
        DB::statement('ALTER TABLE sunat_mapeos MODIFY COLUMN descripcion_sunat TEXT NULL');
        DB::statement('ALTER TABLE concepto_codigos_plame MODIFY COLUMN descripcion_sunat TEXT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['tipos_ausencia', 'sunat_mapeos', 'concepto_codigos_plame'] as $tabla) {
                Schema::table($tabla, fn (Blueprint $table) => $table->string('descripcion_sunat')->nullable()->change());
            }
            return;
        }
        DB::statement('ALTER TABLE tipos_ausencia MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
        DB::statement('ALTER TABLE sunat_mapeos MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
        DB::statement('ALTER TABLE concepto_codigos_plame MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
    }
};
