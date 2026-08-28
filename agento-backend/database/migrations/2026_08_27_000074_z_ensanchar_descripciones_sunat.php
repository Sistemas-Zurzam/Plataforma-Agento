<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement('ALTER TABLE tipos_ausencia MODIFY COLUMN descripcion_sunat TEXT NULL');
        DB::statement('ALTER TABLE sunat_mapeos MODIFY COLUMN descripcion_sunat TEXT NULL');
        DB::statement('ALTER TABLE concepto_codigos_plame MODIFY COLUMN descripcion_sunat TEXT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE tipos_ausencia MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
        DB::statement('ALTER TABLE sunat_mapeos MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
        DB::statement('ALTER TABLE concepto_codigos_plame MODIFY COLUMN descripcion_sunat VARCHAR(255) NULL');
    }
};
