<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de pruebas de Agento y filtro psicométrico por proceso.
 *
 * - seleccion_pruebas.empresa_id NULL = prueba del banco único de Agento,
 *   disponible en los procesos de todas las empresas. Las pruebas con
 *   empresa siguen funcionando como antes.
 * - Dimensiones con lo que pide una guía de corrección: código, niveles por
 *   puntaje bruto (bajo/medio/alto), escala de validez con umbral de alerta,
 *   interpretación y pregunta de entrevista.
 * - Perfiles de puesto sugeridos por la prueba (dimensiones a priorizar).
 * - Resultado bruto por dimensión (suma) y conclusión del evaluador.
 * - Por proceso: prueba que se envía al postular (por familia: siempre la
 *   última versión publicada), nivel mínimo y dimensiones prioritarias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seleccion_pruebas', function (Blueprint $table) {
            $table->foreignId('empresa_id')->nullable()->change();
        });

        Schema::table('seleccion_prueba_dimensiones', function (Blueprint $table) {
            $table->string('codigo', 10)->nullable()->after('prueba_id');
            $table->boolean('es_validez')->default(false)->after('peso');
            $table->decimal('nivel_medio_desde', 6, 2)->nullable()->after('es_validez');
            $table->decimal('nivel_alto_desde', 6, 2)->nullable()->after('nivel_medio_desde');
            $table->decimal('alerta_desde', 6, 2)->nullable()->after('nivel_alto_desde');
            $table->text('interpretacion_alta')->nullable()->after('alerta_desde');
            $table->text('interpretacion_baja')->nullable()->after('interpretacion_alta');
            $table->text('pregunta_entrevista')->nullable()->after('interpretacion_baja');
        });

        Schema::create('seleccion_prueba_perfiles_puesto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->cascadeOnDelete();
            $table->string('nombre', 120);
            $table->unsignedSmallInteger('orden')->default(0);
        });

        Schema::create('seleccion_prueba_perfil_dimensiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('seleccion_prueba_perfiles_puesto')->cascadeOnDelete();
            $table->string('codigo', 10);
            $table->unique(['perfil_id', 'codigo'], 'sel_prueba_perfil_dim_unique');
        });

        Schema::table('seleccion_intento_resultados', function (Blueprint $table) {
            $table->decimal('suma', 8, 2)->nullable()->after('puntaje');
        });

        Schema::table('seleccion_prueba_intentos', function (Blueprint $table) {
            $table->string('conclusion', 25)->nullable()->after('puntaje');
            $table->text('conclusion_comentario')->nullable()->after('conclusion');
            $table->foreignId('concluido_por')->nullable()->after('conclusion_comentario')->constrained('users')->nullOnDelete();
            $table->timestamp('concluido_at')->nullable()->after('concluido_por');
        });

        Schema::table('seleccion_procesos', function (Blueprint $table) {
            $table->string('prueba_familia', 36)->nullable()->after('empresa_confidencial');
            $table->string('prueba_nivel_minimo', 10)->nullable()->after('prueba_familia');
        });

        Schema::create('seleccion_proceso_dimensiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proceso_id')->constrained('seleccion_procesos')->cascadeOnDelete();
            $table->string('codigo', 10);
            $table->unique(['proceso_id', 'codigo'], 'sel_proceso_dim_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seleccion_proceso_dimensiones');

        Schema::table('seleccion_procesos', function (Blueprint $table) {
            $table->dropColumn(['prueba_familia', 'prueba_nivel_minimo']);
        });

        Schema::table('seleccion_prueba_intentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concluido_por');
            $table->dropColumn(['conclusion', 'conclusion_comentario', 'concluido_at']);
        });

        Schema::table('seleccion_intento_resultados', function (Blueprint $table) {
            $table->dropColumn('suma');
        });

        Schema::dropIfExists('seleccion_prueba_perfil_dimensiones');
        Schema::dropIfExists('seleccion_prueba_perfiles_puesto');

        Schema::table('seleccion_prueba_dimensiones', function (Blueprint $table) {
            $table->dropColumn([
                'codigo', 'es_validez', 'nivel_medio_desde', 'nivel_alto_desde', 'alerta_desde',
                'interpretacion_alta', 'interpretacion_baja', 'pregunta_entrevista',
            ]);
        });

        // Las pruebas del banco de Agento no tienen empresa: no se puede
        // volver a NOT NULL sin decidir a quién pertenecen.
    }
};
