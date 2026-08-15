<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('horario_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('horario_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->string('estado')->default('pendiente');
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->time('refrigerio_inicio')->nullable();
            $table->time('refrigerio_fin')->nullable();
            $table->boolean('jornada_nocturna')->default(false);
            $table->boolean('permitir_horas_extra')->default(false);
            $table->timestamps();

            $table->unique(['horario_id', 'dia_semana']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('horario_dias');
    }
};
