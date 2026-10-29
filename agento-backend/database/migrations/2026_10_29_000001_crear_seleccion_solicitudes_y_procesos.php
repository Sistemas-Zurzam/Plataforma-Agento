<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selección y Reclutamiento — solicitudes de personal, procesos de selección,
 * perfiles versionados, plazas individuales, actividad y tareas.
 *
 * Una solicitud aceptada genera UN proceso (unique solicitud_id) con N plazas
 * (unique proceso_id + numero): la publicación y los candidatos pertenecen al
 * proceso, no a cada plaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seleccion_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->foreignId('solicitante_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cargo', 150);
            $table->string('motivo', 20);
            $table->unsignedTinyInteger('plazas');
            $table->decimal('salario_min', 12, 2);
            $table->decimal('salario_max', 12, 2);
            $table->string('moneda', 3)->default('PEN');
            $table->string('tipo_contrato', 30);
            $table->string('modalidad', 20);
            $table->date('fecha_requerida');
            $table->text('descripcion');
            $table->text('actividades')->nullable();
            $table->text('justificacion')->nullable();
            $table->decimal('presupuesto', 14, 2)->nullable();
            $table->boolean('urgente')->default(false);
            $table->string('estado', 20)->default('borrador');
            $table->timestamp('enviada_at')->nullable();
            $table->text('decision_motivo')->nullable();
            $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_at')->nullable();
            $table->text('cancelacion_motivo')->nullable();
            $table->foreignId('cancelada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelada_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
            $table->index(['empresa_id', 'area_id']);
        });

        Schema::create('seleccion_solicitud_habilidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_id')->constrained('seleccion_solicitudes')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->unsignedTinyInteger('nivel');
        });

        Schema::create('seleccion_procesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('solicitud_id')->unique()->constrained('seleccion_solicitudes')->restrictOnDelete();
            $table->string('codigo', 20);
            $table->foreignId('area_id')->constrained()->restrictOnDelete();
            $table->foreignId('reclutador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cargo', 150);
            $table->text('descripcion');
            $table->string('modalidad', 20);
            $table->string('tipo_contrato', 30);
            $table->string('ubicacion', 150)->nullable();
            $table->decimal('salario_min', 12, 2);
            $table->decimal('salario_max', 12, 2);
            $table->string('moneda', 3)->default('PEN');
            $table->boolean('mostrar_salario')->default(false);
            $table->boolean('empresa_confidencial')->default(false);
            $table->date('fecha_requerida');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_cierre_postulacion')->nullable();
            $table->string('estado', 20)->default('borrador');
            // Motivo interno (pausa/cierre/cancelación) separado del mensaje
            // que ve el candidato: nunca se publica el motivo interno.
            $table->text('motivo_estado')->nullable();
            $table->string('mensaje_publico', 300)->nullable();
            $table->boolean('publicacion_interna')->default(false);
            $table->boolean('publicacion_externa')->default(false);
            $table->timestamp('publicada_interna_at')->nullable();
            $table->timestamp('publicada_externa_at')->nullable();
            $table->timestamp('retirada_at')->nullable();
            $table->timestamp('cubierto_at')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
            $table->index(['estado', 'publicacion_externa']);
            $table->index(['empresa_id', 'area_id']);
        });

        Schema::create('seleccion_perfiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proceso_id')->constrained('seleccion_procesos')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('umbral_ats')->default(60);
            $table->unsignedTinyInteger('peso_ats');
            $table->unsignedTinyInteger('peso_psicometria');
            $table->unsignedTinyInteger('peso_entrevistas');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proceso_id', 'version']);
        });

        Schema::create('seleccion_perfil_requisitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('seleccion_perfiles')->cascadeOnDelete();
            $table->string('tipo', 15);
            $table->string('descripcion', 300);
            $table->unsignedSmallInteger('orden')->default(0);
        });

        Schema::create('seleccion_perfil_competencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('seleccion_perfiles')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->unsignedTinyInteger('nivel');
            $table->unsignedTinyInteger('peso');
        });

        Schema::create('seleccion_plazas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proceso_id')->constrained('seleccion_procesos')->cascadeOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('estado', 15)->default('disponible');
            // FK a postulaciones se agrega en la migración siguiente. El
            // unique impide que una misma postulación cubra dos plazas.
            $table->unsignedBigInteger('postulacion_id')->nullable()->unique();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamp('cubierta_at')->nullable();
            $table->timestamp('cancelada_at')->nullable();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamps();

            $table->unique(['proceso_id', 'numero']);
            $table->index(['proceso_id', 'estado']);
        });

        Schema::create('seleccion_actividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('entidad_tipo', 40);
            $table->unsignedBigInteger('entidad_id');
            $table->string('accion', 60);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_tipo', 20)->default('usuario');
            $table->text('motivo')->nullable();
            $table->json('cambios')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['empresa_id', 'entidad_tipo', 'entidad_id']);
        });

        Schema::create('seleccion_tareas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('titulo', 200);
            $table->text('detalle')->nullable();
            // Quién debe atenderla, expresado con un permiso existente (p. ej.
            // colaboradores.editar para Nóminas) en vez de un rol fijo.
            $table->string('permiso_responsable', 80);
            $table->unsignedBigInteger('postulacion_id')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente');
            $table->foreignId('completada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completada_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_tareas');
        Schema::dropIfExists('seleccion_actividades');
        Schema::dropIfExists('seleccion_plazas');
        Schema::dropIfExists('seleccion_perfil_competencias');
        Schema::dropIfExists('seleccion_perfil_requisitos');
        Schema::dropIfExists('seleccion_perfiles');
        Schema::dropIfExists('seleccion_procesos');
        Schema::dropIfExists('seleccion_solicitud_habilidades');
        Schema::dropIfExists('seleccion_solicitudes');
    }
};
