<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistencia_periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado', 20)->default('abierto');
            $table->timestamp('cerrado_at')->nullable();
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_nomina_at')->nullable();
            $table->foreignId('enviado_nomina_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'fecha_inicio', 'fecha_fin'], 'asistencia_periodos_empresa_fechas_unique');
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('asistencia_importaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('importado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archivo_nombre');
            $table->char('archivo_hash', 64);
            $table->string('estado', 20)->default('procesando');
            $table->date('fecha_minima')->nullable();
            $table->date('fecha_maxima')->nullable();
            $table->unsignedInteger('filas_totales')->default(0);
            $table->unsignedInteger('filas_nuevas')->default(0);
            $table->unsignedInteger('filas_duplicadas')->default(0);
            $table->unsignedInteger('filas_observadas')->default(0);
            $table->json('errores')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
            $table->index(['empresa_id', 'archivo_hash']);
        });

        Schema::create('asistencia_marcaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('person_id', 100);
            $table->dateTime('marcado_at');
            $table->string('origen', 50)->default('transaction');
            $table->string('dispositivo', 100)->nullable();
            $table->json('datos_origen')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresa_id', 'person_id', 'marcado_at', 'origen'],
                'asistencia_marcaciones_identidad_unique'
            );
            $table->index(['empresa_id', 'colaborador_id', 'marcado_at'], 'asistencia_marcaciones_colaborador_fecha_index');
            $table->index(['empresa_id', 'person_id', 'marcado_at'], 'asistencia_marcaciones_persona_fecha_index');
        });

        Schema::create('asistencia_importacion_marcaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacion_id')->constrained('asistencia_importaciones')->cascadeOnDelete();
            $table->foreignId('marcacion_id')->constrained('asistencia_marcaciones')->cascadeOnDelete();
            $table->boolean('fue_nueva')->default(false);
            $table->timestamps();

            $table->unique(['importacion_id', 'marcacion_id'], 'asistencia_importacion_marcacion_unique');
        });

        Schema::create('asistencia_resultados_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('periodo_id')->nullable()->constrained('asistencia_periodos')->nullOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('horario_asignacion_id')->nullable()
                ->constrained('colaborador_horario_asignaciones')->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo_dia', 30);
            $table->string('estado', 30);
            $table->dateTime('entrada_at')->nullable();
            $table->dateTime('salida_at')->nullable();
            $table->unsignedInteger('minutos_programados')->default(0);
            $table->unsignedInteger('minutos_trabajados')->default(0);
            $table->unsignedInteger('minutos_tardanza')->default(0);
            $table->unsignedInteger('minutos_salida_anticipada')->default(0);
            $table->unsignedInteger('minutos_extra_observados')->default(0);
            $table->unsignedInteger('minutos_extra_25')->default(0);
            $table->unsignedInteger('minutos_extra_35')->default(0);
            $table->unsignedInteger('minutos_extra_100')->default(0);
            $table->timestamp('procesado_at');
            $table->timestamps();

            $table->unique(['empresa_id', 'colaborador_id', 'fecha'], 'asistencia_resultado_diario_unique');
            $table->index(['empresa_id', 'fecha', 'estado']);
            $table->index(['empresa_id', 'periodo_id']);
        });

        Schema::create('asistencia_resultado_marcaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resultado_diario_id')->constrained('asistencia_resultados_diarios')->cascadeOnDelete();
            $table->foreignId('marcacion_id')->constrained('asistencia_marcaciones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['resultado_diario_id', 'marcacion_id'], 'asistencia_resultado_marcacion_unique');
        });

        Schema::create('asistencia_incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('resultado_diario_id')->constrained('asistencia_resultados_diarios')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 40);
            $table->string('estado', 20)->default('pendiente');
            $table->text('descripcion')->nullable();
            $table->text('motivo_resolucion')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();

            $table->unique(['resultado_diario_id', 'tipo'], 'asistencia_incidencia_resultado_tipo_unique');
            $table->index(['empresa_id', 'estado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_incidencias');
        Schema::dropIfExists('asistencia_resultado_marcaciones');
        Schema::dropIfExists('asistencia_resultados_diarios');
        Schema::dropIfExists('asistencia_importacion_marcaciones');
        Schema::dropIfExists('asistencia_marcaciones');
        Schema::dropIfExists('asistencia_importaciones');
        Schema::dropIfExists('asistencia_periodos');
    }
};
