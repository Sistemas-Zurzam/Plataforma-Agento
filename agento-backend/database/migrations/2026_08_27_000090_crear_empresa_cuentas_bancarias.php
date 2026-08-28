<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas bancarias de la EMPRESA (cuenta de cargo para Telecrédito y
 * futuras integraciones bancarias) — Sección 7/11 del encargo Telecrédito.
 * Normalizada, reutilizable, sin campos bancarios que Agento no necesita
 * todavía (SWIFT, agencia, tasas, conciliación — Sección 5).
 *
 * `tipo_cuenta` guarda el valor SEMÁNTICO de Agento ('corriente'|'maestra'
 * — nunca "C"/"M" directamente, Sección 10): la traducción a los códigos
 * BCP vive en TelecreditoBcpFormato, no acá.
 *
 * `uso` distingue para qué sirve cada cuenta (ej. 'haberes') — una empresa
 * puede tener cuentas BCP que NO deban ofrecerse para pagar planilla
 * (Sección 13). `es_predeterminada` es solo una sugerencia de UI (Sección
 * 14): el usuario siempre puede elegir otra cuenta habilitada al generar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('banco_id')->constrained('bancos')->restrictOnDelete();
            $table->string('tipo_cuenta'); // corriente | maestra
            $table->string('moneda'); // PEN | USD
            $table->string('numero_cuenta', 20); // string — nunca integer (Sección 12), preserva ceros iniciales
            $table->string('uso')->default('haberes');
            $table->boolean('es_predeterminada')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'numero_cuenta'], 'empresa_cuentas_bancarias_unico');
            $table->index(['empresa_id', 'uso', 'activo'], 'empresa_cuentas_bancarias_uso_indice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_cuentas_bancarias');
    }
};
