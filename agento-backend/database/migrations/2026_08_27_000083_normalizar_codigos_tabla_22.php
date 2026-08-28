<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige el formato canónico de los códigos de Tabla 22 (SUNAT):
     * "Longitud 4" según el Anexo 3 (E18, campo 3) — las migraciones
     * 000075/000080 cargaron valores correctos pero SIN el cero inicial
     * ("121" en vez de "0121"). Es una corrección de FORMATO, no de valor
     * semántico (121 y 0121 son el mismo código) — no altera qué código se
     * usó históricamente, solo su representación canónica como string de 4
     * dígitos.
     *
     * Alcance deliberadamente acotado a las 4 columnas que son
     * específicamente Tabla 22 (conceptos_remuneracion.codigo_plame,
     * concepto_codigos_plame.codigo_plame, concepto_definiciones_plame.codigo_plame,
     * boleta_conceptos.codigo_plame_snapshot) — NUNCA se toca
     * sunat_mapeos.codigo_sunat ni tipos_ausencia.codigo_sunat_suspension,
     * que son catálogos con longitudes oficiales distintas (Tabla 3/21/23/33).
     */
    public function up(): void
    {
        $columnas = [
            'conceptos_remuneracion' => 'codigo_plame',
            'concepto_codigos_plame' => 'codigo_plame',
            'concepto_definiciones_plame' => 'codigo_plame',
            'boleta_conceptos' => 'codigo_plame_snapshot',
        ];

        foreach ($columnas as $tabla => $columna) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            DB::statement(<<<SQL
                UPDATE {$tabla}
                SET {$columna} = LPAD({$columna}, 4, '0')
                WHERE {$columna} IS NOT NULL
                  AND {$columna} REGEXP '^[0-9]+$'
                  AND CHAR_LENGTH({$columna}) < 4
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Corrección de formato — no se revierte (quitar el cero inicial no
        // tiene ningún beneficio y arriesgaría reintroducir el bug).
    }
};
