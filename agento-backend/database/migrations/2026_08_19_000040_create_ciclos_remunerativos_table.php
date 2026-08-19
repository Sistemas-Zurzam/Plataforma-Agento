<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ciclos_remunerativos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre'); // ej. "Agosto 2026"
            $table->string('periodicidad')->default('mensual');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->date('fecha_corte_asistencia');
            $table->date('fecha_pago');
            $table->string('estado')->default('abierto'); // abierto | calculado | cerrado | pagado | reabierto
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['empresa_id', 'fecha_inicio', 'fecha_fin'], 'ciclos_remunerativos_rango_unique');
            $table->index(['empresa_id', 'estado'], 'ciclos_remunerativos_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ciclos_remunerativos');
    }
};
