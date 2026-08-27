<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trabajador de confianza: no importa si falta ni si tiene horas extra o
 * no — se le paga el sueldo básico completo cada período, sin descuento por
 * tardanza ni ingreso por horas extra. Los aportes obligatorios (AFP/ONP,
 * EsSalud, renta 5ta) NO se ven afectados por esta bandera — siguen
 * calculándose normal sobre el sueldo básico completo (confirmado
 * explícitamente, no son negociables por tipo de trabajador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->boolean('es_trabajador_confianza')->default(false)->after('tipo_trabajador');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn('es_trabajador_confianza');
        });
    }
};
