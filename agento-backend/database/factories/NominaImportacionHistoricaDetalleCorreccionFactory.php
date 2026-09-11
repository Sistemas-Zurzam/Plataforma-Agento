<?php

namespace Database\Factories;

use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalleCorreccion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NominaImportacionHistoricaDetalleCorreccion>
 */
class NominaImportacionHistoricaDetalleCorreccionFactory extends Factory
{
    protected $model = NominaImportacionHistoricaDetalleCorreccion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'detalle_id' => NominaImportacionHistoricaDetalle::factory(),
            'campo' => 'colaborador_id',
            'valor_anterior' => null,
            'valor_nuevo' => '1',
            'motivo' => 'Corrección de prueba automatizada',
            'corregido_at' => now(),
        ];
    }
}
