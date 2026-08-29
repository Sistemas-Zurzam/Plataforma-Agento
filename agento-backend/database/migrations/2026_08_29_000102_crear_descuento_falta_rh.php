<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'DESCUENTO_FALTA'],
            [
                'nombre' => 'Descuento por falta',
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
        ConceptoRemuneracion::where('codigo', 'DESCUENTO_FALTA')->delete();
    }
};
