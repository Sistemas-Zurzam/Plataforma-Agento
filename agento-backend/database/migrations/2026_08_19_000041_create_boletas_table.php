<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una boleta nunca se sobrescribe: recalcular crea una nueva fila con
     * version+1 y es_version_vigente=true, y se apaga el flag de la
     * anterior — así queda historial completo de versiones de cálculo
     * (Sección 56 del encargo) sin perder trazabilidad.
     * snapshot_parametros_version / snapshot_reglas_version son
     * obligatorios: permiten reproducir exactamente un cálculo pasado
     * aunque los parámetros o el motor ya hayan cambiado.
     */
    public function up(): void
    {
        Schema::create('boletas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('es_version_vigente')->default(true);
            $table->string('regimen_laboral_snapshot');
            $table->decimal('sueldo_basico_snapshot', 10, 2);
            $table->decimal('dias_pagados', 5, 2);
            $table->decimal('total_ingresos', 12, 2);
            $table->decimal('total_egresos', 12, 2);
            $table->decimal('total_aportaciones', 12, 2);
            $table->decimal('neto_a_pagar', 12, 2);
            $table->string('estado')->default('calculada'); // calculada | observada | aprobada | pagada | anulada
            $table->string('snapshot_parametros_version');
            $table->string('snapshot_reglas_version');
            $table->string('motivo_recalculo')->nullable();
            $table->foreignId('calculado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculado_at');
            $table->timestamps();

            $table->index(['ciclo_id', 'colaborador_id', 'es_version_vigente'], 'boletas_ciclo_colaborador_idx');
            $table->unique(['ciclo_id', 'colaborador_id', 'version'], 'boletas_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boletas');
    }
};
