<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

/**
 * Mismo mecanismo y clasificación que los descuentos operativos de
 * 2026_08_30_000104: egreso no remunerativo que no afecta bases, aportes ni
 * beneficios. firstOrCreate: si el concepto ya existe no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'DESCUENTO_CONSUMO_COMIDA'],
            [
                'nombre' => 'Descuento por consumo de comida',
                'tipo' => 'egreso',
                'es_remunerativo_laboral' => false,
                'afecta_renta_5ta' => false,
                'afecta_afp' => false,
                'afecta_essalud' => false,
                'afecta_cts' => false,
                'afecta_gratificacion' => false,
                'afecta_vacaciones' => false,
                'activo' => true,
                'sunat_motivo_estado' => 'Concepto operativo de descuento; requiere clasificación SUNAT explícita antes de declararse en PLAME.',
            ],
        );
    }

    public function down(): void
    {
        ConceptoRemuneracion::where('codigo', 'DESCUENTO_CONSUMO_COMIDA')->delete();
    }
};
