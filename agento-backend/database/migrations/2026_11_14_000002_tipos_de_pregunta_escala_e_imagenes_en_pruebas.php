<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preguntas dinámicas en el banco de pruebas:
 *
 * - Escala de la prueba (ej. Likert 1–5 con sus etiquetas), definida una vez.
 * - Tipo de pregunta: "escala" (afirmación que usa la escala; puede ser
 *   invertida) u "opciones" (opciones propias con su valor: conocimientos,
 *   razonamiento, ítems visuales, situacionales).
 * - Imágenes en el enunciado y en cada opción. Se guardan en disco privado y
 *   se sirven con URL firmada temporal; las versiones de una prueba
 *   comparten la imagen (es inmutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seleccion_prueba_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disco', 30);
            $table->string('ruta', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('tamano');
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seleccion_prueba_escala', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prueba_id')->constrained('seleccion_pruebas')->cascadeOnDelete();
            $table->decimal('valor', 5, 2);
            $table->string('etiqueta', 120);
            $table->unsignedSmallInteger('orden')->default(0);
        });

        Schema::table('seleccion_prueba_preguntas', function (Blueprint $table) {
            $table->string('tipo', 15)->default('opciones')->after('dimension_id');
            $table->boolean('invertida')->default(false)->after('tipo');
            $table->foreignId('imagen_id')->nullable()->after('enunciado')->constrained('seleccion_prueba_imagenes')->nullOnDelete();
        });

        Schema::table('seleccion_prueba_opciones', function (Blueprint $table) {
            $table->foreignId('imagen_id')->nullable()->after('texto')->constrained('seleccion_prueba_imagenes')->nullOnDelete();
        });

        // Una opción puede ser solo imagen.
        Schema::table('seleccion_prueba_opciones', function (Blueprint $table) {
            $table->string('texto', 500)->nullable()->change();
        });

        DB::table('seleccion_prueba_preguntas')->update(['tipo' => 'opciones']);
    }

    public function down(): void
    {
        Schema::table('seleccion_prueba_opciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imagen_id');
        });

        Schema::table('seleccion_prueba_preguntas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imagen_id');
            $table->dropColumn(['tipo', 'invertida']);
        });

        Schema::dropIfExists('seleccion_prueba_escala');
        Schema::dropIfExists('seleccion_prueba_imagenes');
    }
};
