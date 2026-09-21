<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monto mensual fijo del Bono de Asistencia por colaborador (política Livex,
 * personal comercial) — se reduce por el motor de cálculo según faltas y
 * tardanzas del mes, nunca se modifica directamente. Vive en
 * colaborador_remuneraciones (no en una tabla nueva) porque ya es la tabla
 * histórica por vigencia_desde de "montos configurados por colaborador": si
 * el bono base cambia, debe quedar una nueva fila vigente sin perder el
 * historial, igual que el salario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_remuneraciones', function (Blueprint $table) {
            $table->decimal('bono_asistencia_base', 10, 2)->nullable()->after('asignacion_familiar');
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_remuneraciones', function (Blueprint $table) {
            $table->dropColumn('bono_asistencia_base');
        });
    }
};
