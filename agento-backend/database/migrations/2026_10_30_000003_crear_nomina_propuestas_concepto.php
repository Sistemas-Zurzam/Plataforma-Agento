<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propuestas de bono/comisión hechas por gerencia desde el Portal de
 * Empresa. colaborador_conceptos_periodo no tiene estado y el motor de
 * planilla lo consume directamente, por eso una propuesta vive aquí hasta
 * que RR. HH. la valida: recién entonces se registra el concepto del ciclo
 * con CicloRemunerativoService::registrarConcepto() y se guarda el vínculo
 * (colaborador_concepto_periodo_id) para trazabilidad.
 *
 * clave_idempotencia: generada por el cliente por intento de envío; un
 * reintento con la misma clave devuelve la propuesta ya creada, mientras
 * que una segunda propuesta legítima trae una clave nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nomina_propuestas_concepto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->string('tipo', 20);
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 500);
            $table->string('origen', 20)->default('portal');
            $table->string('estado', 20)->default('propuesto');
            $table->string('observacion_rrhh', 500)->nullable();
            $table->foreignId('propuesto_por')->constrained('users')->restrictOnDelete();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->foreignId('colaborador_concepto_periodo_id')->nullable()
                ->constrained('colaborador_conceptos_periodo', 'id', 'nom_prop_concepto_periodo_fk')->nullOnDelete();
            $table->uuid('clave_idempotencia');
            $table->timestamps();

            $table->unique(['empresa_id', 'propuesto_por', 'clave_idempotencia'], 'nom_prop_idempotencia_unique');
            $table->index(['empresa_id', 'estado'], 'nom_prop_empresa_estado_idx');
            $table->index(['ciclo_id', 'colaborador_id'], 'nom_prop_ciclo_colab_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomina_propuestas_concepto');
    }
};
