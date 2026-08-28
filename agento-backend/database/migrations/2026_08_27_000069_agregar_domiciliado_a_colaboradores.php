<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Domiciliado" (tributariamente, en Perú) aparece en varias estructuras
 * del Anexo 3 — E7 (prestador RH), E26 (trabajador, otras condiciones) —
 * como un atributo de la PERSONA, no específico de un régimen. Se agrega a
 * Colaborador en general (reutilizando la identidad ya existente, sin
 * duplicarla) en vez de a la tabla de comprobantes RH.
 *
 * Default true: la gran mayoría de colaboradores registrados en un sistema
 * peruano son domiciliados — evita que todo el histórico existente quede
 * marcado "no domiciliado" por defecto, que sería el valor incorrecto con
 * más frecuencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('domiciliado')->default(true)->after('pais_residencia');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('domiciliado');
        });
    }
};
