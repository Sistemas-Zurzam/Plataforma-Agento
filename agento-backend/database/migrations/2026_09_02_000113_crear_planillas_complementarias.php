<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planillas_complementarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('nombre');
            $table->text('motivo');
            $table->string('estado')->default('calculada'); // calculada | aprobada | pagada
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprobado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable();
            $table->foreignId('pagado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pagado_at')->nullable();
            $table->string('referencia_pago')->nullable();
            $table->timestamps();

            $table->index(['ciclo_id', 'estado']);
        });

        Schema::create('planilla_complementaria_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planilla_complementaria_id');
            $table->foreign('planilla_complementaria_id', 'pc_detalles_complementaria_fk')
                ->references('id')->on('planillas_complementarias')->cascadeOnDelete();
            $table->foreignId('boleta_original_id')->constrained('boletas')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->nullOnDelete();
            $table->string('tipo_cuenta_snapshot')->nullable();
            $table->string('moneda_snapshot')->nullable();
            $table->string('numero_cuenta_snapshot')->nullable();
            $table->string('cci_snapshot')->nullable();
            $table->decimal('neto_original', 12, 2);
            $table->decimal('neto_recalculado', 12, 2);
            $table->decimal('diferencia_ingresos', 12, 2);
            $table->decimal('diferencia_egresos', 12, 2);
            $table->decimal('diferencia_aportaciones', 12, 2);
            $table->decimal('diferencia_neta', 12, 2);
            $table->json('calculo_snapshot');
            $table->timestamps();

            $table->unique(['planilla_complementaria_id', 'colaborador_id'], 'complementaria_colaborador_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_complementaria_detalles');
        Schema::dropIfExists('planillas_complementarias');
    }
};
