<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura E20/.4ta de PLAME (comprobantes de Prestadores de Servicios -
 * renta de 4ta). Deliberadamente NO se agregan estos campos a `boletas`
 * (violaría 3FN: son datos que solo aplican al ~subconjunto de boletas de
 * locadores, nunca a un dependiente) — entidad 1:1 separada, igual que
 * beneficio_social_detalles es detalle de beneficios_sociales.
 *
 * empresa_id propio (aunque sea derivable de boleta_id → boletas.empresa_id)
 * seguiría el patrón "toda tabla de negocio nueva incluye empresa_id" de la
 * skill multitenant-scoping — se omite acá a propósito porque el acceso
 * SIEMPRE pasa por la boleta padre ya autorizada (mismo criterio ya
 * documentado en colaborador_remuneraciones).
 *
 * Se completa manualmente por RR.HH. después del cálculo (cuando reciben
 * el recibo por honorarios real del locador) — no se auto-genera al
 * calcular la boleta, por eso todas las columnas son nullable salvo
 * boleta_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boleta_comprobantes_rh', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boleta_id')->unique()->constrained('boletas')->cascadeOnDelete();
            // Tabla 23 SUNAT — PENDIENTE DE CATÁLOGO SUNAT, no se valida
            // contra códigos reales hasta tener el Anexo 3 completo.
            $table->string('tipo_comprobante', 1)->nullable();
            $table->string('serie', 4)->nullable();
            $table->string('numero', 8)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->date('fecha_pago')->nullable();
            // Derivado automáticamente del cálculo existente
            // (CalcularReciboHonorarios), nunca una segunda fórmula.
            $table->boolean('indicador_retencion_4ta')->nullable();
            // Estos dos SÍ son manuales: reflejan un acuerdo del locador con
            // su propio régimen pensionario, algo que Agento no calcula
            // (los locadores no tienen AFP/ONP como dependientes).
            $table->string('indicador_retencion_regimen_pensionario', 1)->nullable();
            $table->decimal('importe_aporte_regimen_pensionario', 7, 2)->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boleta_comprobantes_rh');
    }
};
