<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada boleta de CTS (hoja de liquidación del depósito) enviada
 * por correo: a qué correo (copiado al enviar) y con qué resultado. Una fila
 * por envío; los reenvíos agregan filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficio_social_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('beneficio_social_detalle_id')->constrained('beneficio_social_detalles');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->string('email');
            // 'pendiente' (en cola) | 'enviado' | 'fallido'
            $table->string('estado', 20)->default('pendiente');
            $table->string('error', 255)->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();
            $table->index(['beneficio_social_detalle_id', 'estado'], 'beneficio_envios_detalle_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficio_social_envios');
    }
};
