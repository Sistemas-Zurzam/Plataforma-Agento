<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $sunatMotivoEstado = 'Concepto operativo de descuento; requiere clasificación SUNAT explícita antes de declararse en PLAME.';

        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'DESCUENTO_ERROR_OPERATIVO'],
            [
                'nombre' => 'Descuento por error operativo',
                'tipo' => 'egreso',
                'es_remunerativo_laboral' => false,
                'afecta_renta_5ta' => false,
                'afecta_afp' => false,
                'afecta_essalud' => false,
                'afecta_cts' => false,
                'afecta_gratificacion' => false,
                'afecta_vacaciones' => false,
                'activo' => true,
                'sunat_motivo_estado' => $sunatMotivoEstado,
            ],
        );

        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'DESCUENTO_COMPRA_MERCADERIA'],
            [
                'nombre' => 'Descuento por compra de mercadería',
                'tipo' => 'egreso',
                'es_remunerativo_laboral' => false,
                'afecta_renta_5ta' => false,
                'afecta_afp' => false,
                'afecta_essalud' => false,
                'afecta_cts' => false,
                'afecta_gratificacion' => false,
                'afecta_vacaciones' => false,
                'activo' => true,
                'sunat_motivo_estado' => $sunatMotivoEstado,
            ],
        );
    }

    public function down(): void
    {
        ConceptoRemuneracion::whereIn('codigo', ['DESCUENTO_ERROR_OPERATIVO', 'DESCUENTO_COMPRA_MERCADERIA'])->delete();
    }
};
