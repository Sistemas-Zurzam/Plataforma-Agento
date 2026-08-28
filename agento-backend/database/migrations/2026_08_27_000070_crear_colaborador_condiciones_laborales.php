<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('colaborador_condiciones_laborales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->cascadeOnDelete();
            $table->string('regimen_laboral')->nullable();
            $table->string('tipo_contrato')->nullable();
            $table->string('sistema_previsional')->nullable();
            $table->foreignId('afp_id')->nullable()->constrained('afps')->nullOnDelete();
            $table->string('tipo_comision')->nullable();
            $table->date('vigencia_desde');
            $table->timestamps();

            $table->index(['colaborador_id', 'vigencia_desde'], 'colaborador_condiciones_laborales_indice');
        });

        // Backfill: nunca existió historial antes de esta migración, así que
        // solo puede reconstruirse el ESTADO ACTUAL (no los cambios reales
        // que hubo en el pasado). Se registra con vigencia_desde =
        // fecha_ingreso como mejor esfuerzo — mismo criterio ya usado para
        // monto_devengado/monto_pagado_descontado en boleta_conceptos.
        DB::statement(<<<'SQL'
            INSERT INTO colaborador_condiciones_laborales
                (colaborador_id, regimen_laboral, tipo_contrato, sistema_previsional, afp_id, tipo_comision, vigencia_desde, created_at, updated_at)
            SELECT id, regimen_laboral, tipo_contrato, sistema_previsional, afp_id, tipo_comision,
                   COALESCE(fecha_ingreso, CURDATE()), NOW(), NOW()
            FROM colaboradores
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('colaborador_condiciones_laborales');
    }
};
