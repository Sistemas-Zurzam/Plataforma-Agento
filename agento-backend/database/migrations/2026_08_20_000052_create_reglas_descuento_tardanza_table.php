<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reglas escalonadas de descuento por tardanza, configurables por
     * empresa (Sección "CONFIGURACIÓN DE ASISTENCIA - DESCUENTO POR
     * TARDANZA" acordada con el usuario) — ej. "0-10 min sin descuento,
     * 11-30 min por minuto, 31+ medio día". Si una empresa no configura
     * ninguna regla, el motor sigue usando la fórmula plana ya existente
     * (valor_minuto × minutos) — esto es un refinamiento opcional, no
     * reemplaza el comportamiento por defecto.
     */
    public function up(): void
    {
        Schema::create('reglas_descuento_tardanza', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedInteger('minutos_desde');
            $table->unsignedInteger('minutos_hasta')->nullable(); // null = sin límite superior
            $table->string('tipo'); // por_minuto | monto_fijo | medio_dia | dia_completo
            $table->decimal('valor', 10, 2)->nullable(); // requerido para por_minuto/monto_fijo, ignorado en medio_dia/dia_completo
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['empresa_id', 'orden'], 'reglas_descuento_tardanza_orden_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglas_descuento_tardanza');
    }
};
