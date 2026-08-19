<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Línea por colaborador de un beneficio_social calculado — congela los
     * mismos valores que hoy se muestran en la pestaña (sueldo base, meses,
     * bruta, bonificación extraordinaria, neta), tal como quedaron sumados
     * de boleta_conceptos al momento del cálculo.
     */
    public function up(): void
    {
        Schema::create('beneficio_social_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficio_social_id')->constrained('beneficios_sociales')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->decimal('sueldo_basico', 10, 2);
            $table->unsignedTinyInteger('meses');
            $table->decimal('bruta', 12, 2);
            $table->decimal('bonificacion_extraordinaria', 12, 2)->default(0);
            $table->decimal('neta', 12, 2);
            $table->timestamps();

            $table->index(['beneficio_social_id', 'colaborador_id'], 'beneficio_social_detalles_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficio_social_detalles');
    }
};
