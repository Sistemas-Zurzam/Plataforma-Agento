<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 2 (lector de Excel) — ver DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md
 * y el plan aprobado. `clasificacion` es la decisión de negocio del
 * clasificador (qué tipo de fila es: aplicable/no_aplicable/observado/error)
 * — deliberadamente distinta de `estado_validacion` (que sigue siendo el
 * estado de calidad de datos de esa fila). Nullable sin default: mientras
 * no se clasifique no tiene un valor "inválido" por defecto, sino ausencia
 * real de clasificación — en la práctica toda fila queda clasificada antes
 * de que termine `ImportarAntecedentesHistoricosService::importar()`.
 *
 * `referencia_pago_confirmada`/`fecha_pago_confirmada` son compartidas por
 * `confirmarCtsDepositada()` y `confirmarGratificacionPagada()` — ambas
 * representan la misma idea (evidencia de pago que el Excel no confirma
 * por sí solo, ver ESTADO NETO "Cancelado" ≠ depósito/pago verificado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_importacion_historica_detalles', function (Blueprint $table) {
            $table->string('clasificacion', 20)->nullable()->after('tipo_antecedente');
            $table->string('tipo_calculo_original', 30)->nullable()->after('regimen_informado');
            $table->string('colaborador_nombre_original', 180)->nullable()->after('numero_documento_normalizado');
            $table->string('referencia_pago_confirmada', 120)->nullable()->after('fingerprint_negocio');
            $table->date('fecha_pago_confirmada')->nullable()->after('referencia_pago_confirmada');

            $table->index(['importacion_id', 'clasificacion'], 'nomina_import_hist_det_lote_clasificacion_idx');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_importacion_historica_detalles', function (Blueprint $table) {
            $table->dropIndex('nomina_import_hist_det_lote_clasificacion_idx');
            $table->dropColumn([
                'clasificacion', 'tipo_calculo_original', 'colaborador_nombre_original',
                'referencia_pago_confirmada', 'fecha_pago_confirmada',
            ]);
        });
    }
};
