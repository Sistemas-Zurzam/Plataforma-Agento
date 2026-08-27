<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos días de descanso a la semana le corresponden a un colaborador con
 * horario rotativo (varía por persona: 1, 2 o 3 no es un número fijo).
 * Vive en la asignación (no en el colaborador directo) por la misma razón
 * que el horario mismo: si a alguien le cambian de 1 a 2 días de descanso
 * rotativo, debe quedar versionado por fecha, no alterar el cálculo de
 * meses ya pasados. Solo se usa para VALIDAR consistencia contra el rol que
 * se carga a mano — nunca para inferir ni generar el rol automáticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_horario_asignaciones', function (Blueprint $table) {
            $table->unsignedTinyInteger('dias_descanso_rotativo_por_semana')->nullable()->after('horario_id');
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_horario_asignaciones', function (Blueprint $table) {
            $table->dropColumn('dias_descanso_rotativo_por_semana');
        });
    }
};
