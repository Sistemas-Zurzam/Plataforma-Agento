<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

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
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('colaboradores', fn (Blueprint $table) => $table->unsignedBigInteger('horario_id')->nullable()->change());
            return;
        }
        DB::statement('ALTER TABLE colaboradores MODIFY horario_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('colaboradores', fn (Blueprint $table) => $table->unsignedBigInteger('horario_id')->nullable(false)->change());
            return;
        }
        DB::statement('ALTER TABLE colaboradores MODIFY horario_id BIGINT UNSIGNED NOT NULL');
    }
};
