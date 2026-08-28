<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * V3 A9/A10 — descuento por Horas Incompletas (HI): salida anticipada
     * aprobada por RR.HH. como incidencia, remunerada proporcionalmente por
     * PlanillaDependienteCalculator::calcularDescuentoHorasIncompletas().
     * Mismas banderas que DESCUENTO_TARDANZA (egreso, no remunerativo, no
     * afecta AFP/EsSalud/CTS/gratificación/vacaciones) porque es el mismo
     * tipo de concepto: un descuento sobre minutos no trabajados, no un
     * ingreso ni una base de cálculo de otros conceptos.
     *
     * Sin codigo_plame a propósito — la clasificación SUNAT/PLAME de este
     * concepto es una decisión pendiente fuera de esta fase (V3 Fase 1B no
     * modifica PLAME/AFPnet); queda con sunat_no_aplica=false por defecto,
     * igual que cualquier concepto nuevo sin clasificar todavía.
     */
    public function up(): void
    {
        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'DESCUENTO_HORAS_INCOMPLETAS'],
            [
                'nombre' => 'Descuento por horas incompletas (HI)',
                'tipo' => 'egreso',
                'es_remunerativo_laboral' => false,
                'afecta_renta_5ta' => false,
                'afecta_afp' => false,
                'afecta_essalud' => false,
                'afecta_cts' => false,
                'afecta_gratificacion' => false,
                'afecta_vacaciones' => false,
                'activo' => true,
            ],
        );
    }

    public function down(): void
    {
        ConceptoRemuneracion::where('codigo', 'DESCUENTO_HORAS_INCOMPLETAS')->delete();
    }
};
