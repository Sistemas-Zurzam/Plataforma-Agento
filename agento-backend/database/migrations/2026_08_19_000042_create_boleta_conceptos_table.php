<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detalle línea a línea de una boleta. base_utilizada/tasa_aplicada/
     * cantidad/formula_texto quedan congelados en el momento del cálculo
     * (snapshot) para poder mostrar "por qué salió este monto" en la UI sin
     * volver a calcular nada — nunca un texto estático, siempre los valores
     * reales que se usaron.
     */
    public function up(): void
    {
        Schema::create('boleta_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boleta_id')->constrained('boletas')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_remuneracion')->restrictOnDelete();
            $table->string('tipo'); // ingreso | egreso | aportacion (snapshot)
            $table->boolean('es_remunerativo_laboral'); // snapshot
            $table->boolean('afecta_renta_5ta'); // snapshot
            $table->decimal('base_utilizada', 12, 2)->nullable();
            $table->decimal('tasa_aplicada', 8, 4)->nullable();
            $table->decimal('cantidad', 10, 2)->nullable();
            $table->decimal('monto', 12, 2);
            $table->text('formula_texto')->nullable();
            $table->timestamps();

            $table->index(['boleta_id', 'tipo'], 'boleta_conceptos_boleta_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boleta_conceptos');
    }
};
