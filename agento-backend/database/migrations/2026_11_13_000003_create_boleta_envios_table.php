<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada boleta de pago enviada por correo: qué versión de la boleta,
 * a qué correo (copiado al momento del envío) y con qué resultado. Una fila
 * por envío: los reenvíos (nueva versión o reintento) agregan filas, nunca
 * sobrescriben las anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boleta_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('ciclo_id')->constrained('ciclos_remunerativos');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->foreignId('boleta_id')->constrained('boletas');
            $table->unsignedInteger('boleta_version');
            // 'boleta': boleta de pago oficial (después del pago). Deja lugar a otros documentos.
            $table->string('tipo', 20)->default('boleta');
            $table->string('email');
            // 'pendiente' (en cola) | 'enviado' | 'fallido'
            $table->string('estado', 20)->default('pendiente');
            $table->string('error', 255)->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();
            $table->index(['ciclo_id', 'colaborador_id', 'tipo'], 'boleta_envios_ciclo_colaborador_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boleta_envios');
    }
};
