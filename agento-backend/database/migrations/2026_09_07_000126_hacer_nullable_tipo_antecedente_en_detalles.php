<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 2 — una fila `clasificacion=no_aplicable` de planilla ordinaria
 * (BASICO, AFP, ESSALUD, etc.) genuinamente no tiene ningún
 * `tipo_antecedente` de los 11 valores del catálogo: no es "otro" tampoco
 * (ese valor queda reservado para algo reconocible pero no soportado
 * todavía). `tipo_antecedente` nace NOT NULL en el Incremento 1 porque en
 * ese momento se asumía que toda fila tendría uno; el clasificador real
 * (Incremento 2) confirma que no es así.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_importacion_historica_detalles', function (Blueprint $table) {
            $table->string('tipo_antecedente', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('nomina_importacion_historica_detalles', function (Blueprint $table) {
            $table->string('tipo_antecedente', 30)->nullable(false)->change();
        });
    }
};
