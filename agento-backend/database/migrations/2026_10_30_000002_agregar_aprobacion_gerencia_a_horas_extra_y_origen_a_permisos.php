<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal de Empresa — incremental y compatible con los datos existentes:
 *
 * - asistencia_horas_extra: nueva etapa intermedia "aprobado_gerencia"
 *   (estado string existente, sin enum). Los minutos aprobados por gerencia
 *   se guardan aparte de minutos_aprobados (que sigue siendo el valor que
 *   valida RR. HH. y el único que consume Nómina). Todas las columnas son
 *   nullable: las filas históricas no pasaron por gerencia.
 * - asistencia_permisos.origen: 'asistencia' por defecto para todo lo
 *   existente; el portal registra 'portal'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_horas_extra', function (Blueprint $table) {
            $table->unsignedInteger('minutos_aprobados_gerencia')->nullable()->after('minutos_aprobados');
            $table->text('motivo_gerencia')->nullable()->after('motivo');
            $table->foreignId('gerencia_resuelto_por')->nullable()->after('resuelto_at')->constrained('users')->nullOnDelete();
            $table->timestamp('gerencia_resuelto_at')->nullable()->after('gerencia_resuelto_por');
        });

        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->string('origen', 20)->default('asistencia')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropColumn('origen');
        });

        Schema::table('asistencia_horas_extra', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gerencia_resuelto_por');
            $table->dropColumn(['minutos_aprobados_gerencia', 'motivo_gerencia', 'gerencia_resuelto_at']);
        });
    }
};
