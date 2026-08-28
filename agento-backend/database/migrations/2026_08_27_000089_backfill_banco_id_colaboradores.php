<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de `colaboradores.banco_id` (Sección 6 del encargo Telecrédito)
 * — SOLO mapea equivalencias inequívocas: coincidencia EXACTA entre
 * `colaboradores.banco` y `bancos.nombre` (los mismos 7 valores reales que
 * ya ofrece BANCO_OPTIONS en el frontend). Cualquier valor que no
 * coincida exacto (incluido "Otro", que nunca identifica un banco real)
 * se reporta por consola y queda con banco_id = NULL — nunca se adivina.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE colaboradores
            INNER JOIN bancos ON bancos.nombre = colaboradores.banco
            SET colaboradores.banco_id = bancos.id
            WHERE colaboradores.banco IS NOT NULL
        SQL);

        $sinMapear = DB::table('colaboradores')
            ->whereNotNull('banco')
            ->whereNull('banco_id')
            ->distinct()
            ->pluck('banco');

        if ($sinMapear->isNotEmpty()) {
            fwrite(STDOUT, "\n  [backfill_banco_id] Valores de \"banco\" SIN mapear automáticamente (revisar manualmente): "
                .$sinMapear->implode(', ')."\n");
        }
    }

    public function down(): void
    {
        DB::table('colaboradores')->update(['banco_id' => null]);
    }
};
