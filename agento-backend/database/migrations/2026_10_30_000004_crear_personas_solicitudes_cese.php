<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de cese (Portal de Empresa → RR. HH.). La aprobación y la
 * ejecución se guardan por separado: estado = decisión de RR. HH.
 * (enviada|aprobada|rechazada); ejecutada_at/fecha_cese_efectiva solo se
 * llenan cuando ColaboradorService::cesar() completó el cese real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas_solicitudes_cese', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('solicitante_user_id')->constrained('users')->restrictOnDelete();
            $table->string('origen', 20)->default('portal');
            $table->date('fecha_cese_propuesta');
            $table->text('motivo');
            $table->string('estado', 20)->default('enviada');
            $table->date('fecha_cese_aprobada')->nullable();
            $table->text('motivo_cambio_fecha')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resuelto_at')->nullable();
            $table->date('fecha_cese_efectiva')->nullable();
            $table->foreignId('ejecutada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ejecutada_at')->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'estado'], 'pers_sol_cese_empresa_estado_idx');
            $table->index(['colaborador_id', 'estado'], 'pers_sol_cese_colab_estado_idx');
        });

        Schema::create('personas_solicitud_cese_evidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_cese_id')->constrained('personas_solicitudes_cese')->cascadeOnDelete();
            $table->string('nombre_original', 255);
            $table->string('ruta', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('tamano_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas_solicitud_cese_evidencias');
        Schema::dropIfExists('personas_solicitudes_cese');
    }
};
