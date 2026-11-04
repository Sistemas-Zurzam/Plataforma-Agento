<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidato (identidad y contacto, por empresa) separado de Postulación
 * (participación en un proceso). La postulación guarda un snapshot del perfil
 * declarado y referencia al CV inmutable usado al postular — es la única
 * desnormalización intencional: conserva lo que el candidato envió aunque
 * después actualice su perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seleccion_candidatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->timestamp('email_verificado_at')->nullable();
            $table->string('tipo_documento', 15)->nullable();
            $table->string('numero_documento', 20)->nullable();
            $table->string('nombres', 120);
            $table->string('apellido_paterno', 100);
            $table->string('apellido_materno', 100)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('ubicacion', 150)->nullable();
            $table->text('resumen')->nullable();
            $table->timestamp('ultima_postulacion_at')->nullable();
            $table->timestamp('anonimizado_at')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'email']);
            // Sin unique por documento: un unique permitiría deducir desde el
            // portal público si un DNI ya está registrado.
            $table->index(['empresa_id', 'tipo_documento', 'numero_documento'], 'seleccion_candidatos_documento_index');
            $table->index(['empresa_id', 'ultima_postulacion_at']);
        });

        Schema::create('seleccion_candidato_antecedentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->string('tipo', 15);
            $table->string('titulo', 150);
            $table->string('institucion', 150)->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('descripcion')->nullable();
        });

        Schema::create('seleccion_candidato_habilidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->unsignedTinyInteger('nivel')->nullable();

            $table->unique(['candidato_id', 'nombre']);
        });

        Schema::create('seleccion_consentimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->string('version', 30);
            $table->string('texto_hash', 64);
            $table->timestamp('aceptado_at');
            $table->string('ip', 45)->nullable();
            $table->timestamp('revocado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seleccion_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->string('disco', 20)->default('local');
            $table->string('ruta')->nullable();
            $table->string('nombre_original', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');
            $table->string('sha256', 64);
            $table->timestamp('eliminado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seleccion_postulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proceso_id')->constrained('seleccion_procesos')->cascadeOnDelete();
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->string('codigo_seguimiento', 16)->unique();
            $table->string('fuente', 20);
            $table->string('etapa', 30);
            $table->string('estado', 30);
            $table->timestamp('etapa_desde_at')->nullable();
            $table->foreignId('cv_documento_id')->nullable()->constrained('seleccion_documentos')->nullOnDelete();
            $table->json('perfil_snapshot')->nullable();
            $table->unsignedInteger('perfil_version');
            $table->string('causa_descarte', 40)->nullable();
            $table->text('motivo_interno')->nullable();
            $table->string('mensaje_publico', 300)->nullable();
            $table->boolean('no_aplica_ats')->default(false);
            $table->boolean('no_aplica_psicometria')->default(false);
            $table->boolean('no_aplica_entrevistas')->default(false);
            $table->string('extraccion_estado', 20)->default('no_disponible');
            $table->timestamp('contratada_at')->nullable();
            $table->foreignId('colaborador_id')->nullable()->constrained('colaboradores')->nullOnDelete();
            $table->timestamps();

            // Una persona no puede postular dos veces al mismo proceso.
            $table->unique(['proceso_id', 'candidato_id']);
            $table->index(['empresa_id', 'etapa', 'estado']);
        });

        Schema::table('seleccion_plazas', function (Blueprint $table) {
            $table->foreign('postulacion_id')->references('id')->on('seleccion_postulaciones')->nullOnDelete();
        });

        Schema::table('seleccion_tareas', function (Blueprint $table) {
            $table->foreign('postulacion_id')->references('id')->on('seleccion_postulaciones')->nullOnDelete();
        });

        Schema::create('seleccion_postulacion_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('seleccion_postulaciones')->cascadeOnDelete();
            $table->string('etapa_anterior', 30)->nullable();
            $table->string('etapa_nueva', 30);
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->text('motivo')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_tipo', 20)->default('usuario');
            $table->timestamp('created_at')->nullable();

            // Nombre explícito: el autogenerado supera los 64 caracteres de MySQL.
            $table->index(['postulacion_id', 'created_at'], 'seleccion_mov_postulacion_fecha_index');
        });

        Schema::create('seleccion_evaluaciones_ats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('seleccion_postulaciones')->cascadeOnDelete();
            $table->unsignedInteger('perfil_version');
            $table->decimal('puntaje', 5, 2)->nullable();
            $table->string('resultado', 20);
            $table->json('desglose');
            $table->string('formula', 40);
            $table->string('proveedor', 60);
            $table->string('origen', 30);
            $table->foreignId('evaluado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['postulacion_id', 'id']);
        });

        Schema::create('seleccion_puntajes_finales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('seleccion_postulaciones')->cascadeOnDelete();
            $table->unsignedInteger('perfil_version');
            $table->json('pesos');
            $table->json('componentes');
            $table->decimal('puntaje', 5, 2)->nullable();
            $table->boolean('provisional')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['postulacion_id', 'id']);
        });

        Schema::create('seleccion_entrevistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('seleccion_postulaciones')->cascadeOnDelete();
            $table->string('tipo', 20);
            $table->dateTime('programada_at')->nullable();
            $table->string('lugar', 255)->nullable();
            $table->foreignId('evaluador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('programada');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seleccion_entrevista_evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entrevista_id')->constrained('seleccion_entrevistas')->cascadeOnDelete();
            $table->foreignId('evaluador_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('fortalezas');
            $table->text('riesgos');
            $table->string('recomendacion', 20);
            $table->text('comentarios')->nullable();
            $table->decimal('promedio', 4, 2);
            $table->decimal('puntaje', 5, 2);
            $table->timestamps();
        });

        Schema::create('seleccion_entrevista_calificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_id')->constrained('seleccion_entrevista_evaluaciones')->cascadeOnDelete();
            $table->string('competencia', 100);
            $table->unsignedTinyInteger('calificacion');
        });

        Schema::create('seleccion_etiquetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->string('tipo', 15)->default('manual');
            $table->timestamps();

            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('seleccion_candidato_etiqueta', function (Blueprint $table) {
            $table->foreignId('candidato_id')->constrained('seleccion_candidatos')->cascadeOnDelete();
            $table->foreignId('etiqueta_id')->constrained('seleccion_etiquetas')->cascadeOnDelete();
            $table->string('origen', 15)->default('manual');
            $table->timestamps();

            $table->primary(['candidato_id', 'etiqueta_id']);
        });

        Schema::create('seleccion_invitaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proceso_id')->constrained('seleccion_procesos')->cascadeOnDelete();
            $table->foreignId('candidato_id')->nullable()->constrained('seleccion_candidatos')->nullOnDelete();
            $table->string('email', 191);
            $table->string('token_hash', 64)->unique();
            $table->string('tipo', 15);
            $table->timestamp('expira_at');
            $table->timestamp('usada_at')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seleccion_codigos_verificacion', function (Blueprint $table) {
            $table->id();
            $table->string('email_hash', 64)->index();
            $table->string('codigo_hash', 64);
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamp('expira_at');
            $table->timestamp('consumido_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('seleccion_accesos_candidato', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expira_at');
            $table->timestamp('revocado_at')->nullable();
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_accesos_candidato');
        Schema::dropIfExists('seleccion_codigos_verificacion');
        Schema::dropIfExists('seleccion_invitaciones');
        Schema::dropIfExists('seleccion_candidato_etiqueta');
        Schema::dropIfExists('seleccion_etiquetas');
        Schema::dropIfExists('seleccion_entrevista_calificaciones');
        Schema::dropIfExists('seleccion_entrevista_evaluaciones');
        Schema::dropIfExists('seleccion_entrevistas');
        Schema::dropIfExists('seleccion_puntajes_finales');
        Schema::dropIfExists('seleccion_evaluaciones_ats');
        Schema::dropIfExists('seleccion_postulacion_movimientos');
        Schema::table('seleccion_tareas', fn (Blueprint $table) => $table->dropForeign(['postulacion_id']));
        Schema::table('seleccion_plazas', fn (Blueprint $table) => $table->dropForeign(['postulacion_id']));
        Schema::dropIfExists('seleccion_postulaciones');
        Schema::dropIfExists('seleccion_documentos');
        Schema::dropIfExists('seleccion_consentimientos');
        Schema::dropIfExists('seleccion_candidato_habilidades');
        Schema::dropIfExists('seleccion_candidato_antecedentes');
        Schema::dropIfExists('seleccion_candidatos');
    }
};
