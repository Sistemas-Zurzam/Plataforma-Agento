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
        Schema::create('comisiones_afp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('afp_id')->constrained('afps')->cascadeOnDelete();
            $table->date('vigencia_desde');
            $table->decimal('aporte_obligatorio_porcentaje', 6, 2);
            $table->decimal('prima_seguro_porcentaje', 6, 2);
            $table->decimal('comision_flujo_porcentaje', 6, 2);
            $table->decimal('comision_mixta_porcentaje', 6, 2);
            $table->decimal('sobre_saldo_anual_porcentaje', 6, 2);
            $table->timestamps();

            $table->index(['afp_id', 'vigencia_desde'], 'comisiones_afp_indice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comisiones_afp');
    }
};
