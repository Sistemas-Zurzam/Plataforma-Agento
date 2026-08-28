<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * V3 P3 — un trabajador de confianza no necesita horario obligatorio.
     * No se usa ->change() (el proyecto no tiene doctrine/dbal instalado,
     * mismo criterio ya documentado en
     * 2026_08_27_000063_agregar_devengado_pagado_a_boleta_conceptos) — se
     * modifica la columna con SQL nativo. La FK (restrictOnDelete) no se
     * toca: MODIFY COLUMN no altera constraints existentes, solo la
     * nulabilidad. Para cualquier otro colaborador sigue siendo requerido
     * — eso lo valida StoreColaboradorRequest, no la base de datos.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE colaboradores MODIFY horario_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE colaboradores MODIFY horario_id BIGINT UNSIGNED NOT NULL');
    }
};
