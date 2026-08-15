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
        Schema::create('colaborador_remuneraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->decimal('salario', 10, 2);
            $table->string('moneda_salario');
            $table->string('periodicidad_pago');
            $table->decimal('asignacion_familiar', 10, 2)->default(0);
            $table->date('vigencia_desde');
            $table->timestamps();

            $table->index(['colaborador_id', 'vigencia_desde'], 'colaborador_remuneraciones_indice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('colaborador_remuneraciones');
    }
};
