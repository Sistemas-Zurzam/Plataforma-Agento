<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot bancario 1:1 de una Boleta (Sección 22/23/25 del encargo
 * Telecrédito) — mismo patrón que boleta_comprobantes_rh (PLAME): entidad
 * separada porque solo aplica a un subconjunto de boletas (las que
 * realmente se cierran para pago), nunca se agrega a `boletas` directo.
 *
 * Se congela al pasar el ciclo a "cerrado" (CicloRemunerativoService::
 * cerrar(), Sección 23) con los datos bancarios del colaborador EN ESE
 * MOMENTO — si el colaborador cambia de cuenta después, Telecrédito debe
 * seguir usando esta fila, nunca la cuenta actual (Sección 25).
 *
 * NO duplica `neto_a_pagar` (Sección 26) — eso ya vive en `boletas`.
 *
 * banco_id nullable: un colaborador puede no tener banco_id resuelto
 * (ej. su `banco` era "Otro") — el snapshot registra ese hecho tal cual
 * en vez de bloquear el cierre del ciclo; TelecreditoBcpValidator es quien
 * decide más adelante si eso bloquea la exportación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boleta_datos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boleta_id')->unique()->constrained('boletas')->cascadeOnDelete();
            $table->foreignId('banco_id')->nullable()->constrained('bancos')->restrictOnDelete();
            $table->string('tipo_cuenta_snapshot')->nullable(); // ahorro | corriente (valor de Colaborador tal cual)
            $table->string('moneda_snapshot')->nullable(); // PEN | USD
            $table->string('numero_cuenta_snapshot')->nullable();
            $table->string('cci_snapshot')->nullable();
            $table->timestamp('fecha_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boleta_datos_pago');
    }
};
