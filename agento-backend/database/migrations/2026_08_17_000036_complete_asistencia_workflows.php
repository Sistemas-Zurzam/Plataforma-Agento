<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_horas_extra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('resultado_diario_id')->constrained('asistencia_resultados_diarios')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->date('fecha');
            $table->unsignedInteger('minutos_observados')->default(0);
            $table->unsignedInteger('minutos_solicitados')->default(0);
            $table->unsignedInteger('minutos_aprobados')->default(0);
            $table->string('tasa', 10);
            $table->string('estado', 30)->default('pendiente');
            $table->text('motivo')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();
            $table->unique(['resultado_diario_id', 'tasa']);
            $table->index(['empresa_id', 'estado', 'fecha']);
        });

        Schema::create('asistencia_auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('entidad_tipo', 80);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('accion', 60);
            $table->text('motivo')->nullable();
            $table->json('antes')->nullable();
            $table->json('despues')->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'entidad_tipo', 'entidad_id'], 'asistencia_auditoria_entidad_index');
            $table->index(['empresa_id', 'created_at']);
        });

        Schema::table('asistencia_periodos', function (Blueprint $table) {
            $table->json('snapshot_nomina')->nullable()->after('enviado_nomina_por');
            $table->unsignedInteger('version')->default(1)->after('estado');
            $table->timestamp('reabierto_at')->nullable()->after('cerrado_por');
            $table->foreignId('reabierto_por')->nullable()->after('reabierto_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->foreignId('resuelto_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable()->after('resuelto_por');
            $table->text('observacion_resolucion')->nullable()->after('resuelto_at');
        });

        Schema::table('asistencia_marcaciones', function (Blueprint $table) {
            $table->timestamp('anulada_at')->nullable()->after('datos_origen');
            $table->foreignId('anulada_por')->nullable()->after('anulada_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('asistencia_solicitudes_area', function (Blueprint $table) {
            $table->foreignId('responsable_por')->nullable()->after('creado_por')->constrained('users')->nullOnDelete();
            $table->timestamp('responsable_at')->nullable()->after('responsable_por');
            $table->text('observacion_responsable')->nullable()->after('responsable_at');
            $table->foreignId('rrhh_por')->nullable()->after('observacion_responsable')->constrained('users')->nullOnDelete();
            $table->timestamp('rrhh_at')->nullable()->after('rrhh_por');
            $table->text('observacion_rrhh')->nullable()->after('rrhh_at');
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_marcaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('anulada_por');
            $table->dropColumn('anulada_at');
        });
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resuelto_por');
            $table->dropColumn(['resuelto_at', 'observacion_resolucion']);
        });
        Schema::table('asistencia_solicitudes_area', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rrhh_por');
            $table->dropConstrainedForeignId('responsable_por');
            $table->dropColumn(['responsable_at', 'observacion_responsable', 'rrhh_at', 'observacion_rrhh']);
        });
        Schema::table('asistencia_periodos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reabierto_por');
            $table->dropColumn(['snapshot_nomina', 'version', 'reabierto_at']);
        });
        Schema::dropIfExists('asistencia_auditorias');
        Schema::dropIfExists('asistencia_horas_extra');
    }
};
