<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del día forzado a mano por RR.HH. (AsistenciaDecisionService::
 * editarDia()). Hasta ahora el estado forzado solo pisaba `estado`, así que
 * cualquier reproceso posterior lo perdía sin rastro. Con esta columna el
 * motor lo vuelve a aplicar después de recalcular, y solo "Reprocesar todo"
 * (ReprocesarAsistenciaRango con descarte de cambios manuales) la limpia.
 * Los estados forzados antes de esta migración no se pueden reconstruir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->string('estado_forzado', 30)->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->dropColumn('estado_forzado');
        });
    }
};
