<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de pruebas versionadas, asignaciones por postulación, intentos con
 * tiempo controlado por el servidor y respuestas. Las claves (valor de cada
 * opción) viven solo aquí y nunca se serializan hacia endpoints públicos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seleccion_pruebas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            // Agrupa las versiones de una misma prueba.
            $table->string('familia', 36);
            $table->unsignedInteger('version');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->string('tipo', 20);
            $table->unsignedSmallInteger('tiempo_limite_minutos');
            $table->string('estado', 15)->default('borrador');
            // Solo se marca validada con fuente/criterio documentado por quien
            // la aprueba; sin eso se muestra siempre como "no validada".
            $table->boolean('validada')->default(false);
            $table->text('validacion_fuente')->nullable();
            $table->foreignId('validada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validada_at')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'familia', 'version']);
        });

        Schema::create('seleccion_prueba_dimensiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->unsignedTinyInteger('peso');
        });

        Schema::create('seleccion_prueba_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->cascadeOnDelete();
            $table->foreignId('dimension_id')->constrained('seleccion_prueba_dimensiones')->cascadeOnDelete();
            $table->text('enunciado');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->unsignedTinyInteger('peso')->default(1);
        });

        Schema::create('seleccion_prueba_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pregunta_id')->constrained('seleccion_prueba_preguntas')->cascadeOnDelete();
            $table->string('texto', 500);
            $table->decimal('valor', 5, 2);
            $table->unsignedSmallInteger('orden')->default(0);
        });

        Schema::create('seleccion_prueba_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('postulacion_id')->constrained('seleccion_postulaciones')->cascadeOnDelete();
            $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('disponible_hasta');
            $table->string('estado', 15)->default('pendiente');
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['postulacion_id', 'prueba_id']);
        });

        Schema::create('seleccion_prueba_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            // Un único intento por asignación: reconexión, no reintento.
            $table->foreignId('asignacion_id')->unique()->constrained('seleccion_prueba_asignaciones')->cascadeOnDelete();
            $table->timestamp('iniciado_at');
            $table->timestamp('vence_at');
            $table->timestamp('finalizado_at')->nullable();
            $table->string('cierre', 15)->nullable();
            $table->decimal('puntaje', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('seleccion_intento_respuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intento_id')->constrained('seleccion_prueba_intentos')->cascadeOnDelete();
            $table->foreignId('pregunta_id')->constrained('seleccion_prueba_preguntas')->cascadeOnDelete();
            $table->foreignId('opcion_id')->constrained('seleccion_prueba_opciones')->cascadeOnDelete();
            $table->timestamp('respondida_at');

            $table->unique(['intento_id', 'pregunta_id']);
        });

        Schema::create('seleccion_intento_resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intento_id')->constrained('seleccion_prueba_intentos')->cascadeOnDelete();
            $table->foreignId('dimension_id')->constrained('seleccion_prueba_dimensiones')->cascadeOnDelete();
            $table->decimal('puntaje', 5, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_intento_resultados');
        Schema::dropIfExists('seleccion_intento_respuestas');
        Schema::dropIfExists('seleccion_prueba_intentos');
        Schema::dropIfExists('seleccion_prueba_asignaciones');
        Schema::dropIfExists('seleccion_prueba_opciones');
        Schema::dropIfExists('seleccion_prueba_preguntas');
        Schema::dropIfExists('seleccion_prueba_dimensiones');
        Schema::dropIfExists('seleccion_pruebas');
    }
};
