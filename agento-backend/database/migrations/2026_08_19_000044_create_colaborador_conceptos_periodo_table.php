<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comisiones/bonos/adelantos que RR.HH. ingresa para un colaborador
     * ANTES de calcular su boleta de un ciclo puntual (Sección 46 del
     * encargo). El motor de cálculo los recoge automáticamente al calcular
     * la planilla de ese ciclo — no se guardan dentro de la boleta hasta que
     * esta se calcula (la boleta es siempre un resultado derivado, nunca se
     * edita directamente).
     */
    public function up(): void
    {
        Schema::create('colaborador_conceptos_periodo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->foreignId('concepto_id')->constrained('conceptos_remuneracion')->restrictOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('motivo')->nullable();
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ciclo_id', 'colaborador_id'], 'colaborador_conceptos_periodo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_conceptos_periodo');
    }
};
