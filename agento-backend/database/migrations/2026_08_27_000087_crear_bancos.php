<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo mínimo de identidad de banco (Sección 4/5 del encargo
 * Telecrédito) — SOLO lo necesario para saber "esta cuenta es BCP o no",
 * nada de SWIFT/agencia/tasas/conciliación. `codigo` es la identidad
 * interna estable de Agento (nunca un código bancario externo como
 * identidad principal); Telecrédito resuelve BCP específicamente por
 * `codigo = 'bcp'`, no por comparar `nombre`.
 *
 * Sembrado con los mismos 7 bancos que ya ofrece
 * `BANCO_OPTIONS` en el frontend (agento-frontend/src/modules/personas/
 * constants/opciones.js) — "Otro" deliberadamente NO se siembra acá: no
 * identifica un banco real, así que un colaborador con banco="Otro" debe
 * quedar sin banco_id (backfill lo reporta, nunca lo adivina).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bancos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        $filas = [
            ['codigo' => 'bcp', 'nombre' => 'BCP'],
            ['codigo' => 'bbva', 'nombre' => 'BBVA'],
            ['codigo' => 'interbank', 'nombre' => 'Interbank'],
            ['codigo' => 'scotiabank', 'nombre' => 'Scotiabank'],
            ['codigo' => 'banco_nacion', 'nombre' => 'Banco de la Nación'],
            ['codigo' => 'pichincha', 'nombre' => 'Banco Pichincha'],
            ['codigo' => 'gnb', 'nombre' => 'Banco GNB'],
        ];

        $ahora = now();
        foreach ($filas as &$fila) {
            $fila['activo'] = true;
            $fila['created_at'] = $ahora;
            $fila['updated_at'] = $ahora;
        }
        unset($fila);

        DB::table('bancos')->insert($filas);
    }

    public function down(): void
    {
        Schema::dropIfExists('bancos');
    }
};
