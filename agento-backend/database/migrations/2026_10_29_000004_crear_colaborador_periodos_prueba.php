<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Período de prueba del colaborador contratado (dominio Personas). Se
 * registra sobre el colaborador, no sobre la candidatura: es una etapa de la
 * relación laboral, distinta de cualquier evaluación previa a contratar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colaborador_periodos_prueba', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('resultado', 15)->default('en_curso');
            $table->text('observacion')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['empresa_id', 'colaborador_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_periodos_prueba');
    }
};
