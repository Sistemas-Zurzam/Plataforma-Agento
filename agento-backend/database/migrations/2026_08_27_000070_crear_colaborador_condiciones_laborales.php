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
        DB::table('colaboradores')->orderBy('id')->chunkById(500, function ($colaboradores) {
            $ahora = now();
            DB::table('colaborador_condiciones_laborales')->insert($colaboradores->map(fn ($c) => [
                'colaborador_id' => $c->id, 'regimen_laboral' => $c->regimen_laboral,
                'tipo_contrato' => $c->tipo_contrato, 'sistema_previsional' => $c->sistema_previsional,
                'afp_id' => $c->afp_id, 'tipo_comision' => $c->tipo_comision,
                'vigencia_desde' => $c->fecha_ingreso ?: today()->toDateString(),
                'created_at' => $ahora, 'updated_at' => $ahora,
            ])->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('colaborador_condiciones_laborales');
    }
};
