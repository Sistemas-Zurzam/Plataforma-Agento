<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NominaImportacionHistorica>
 */
class NominaImportacionHistoricaFactory extends Factory
{
    protected $model = NominaImportacionHistorica::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'archivo_nombre_original' => 'historico-'.fake()->unique()->numerify('####').'.xlsx',
            'archivo_hash' => hash('sha256', fake()->unique()->uuid()),
            'fecha_corte' => '2026-07-31',
            'estado' => 'borrador',
            'filas_totales' => 0,
            'filas_validas' => 0,
            'filas_observadas' => 0,
            'filas_con_errores' => 0,
            'filas_aplicadas' => 0,
        ];
    }

    public function aplicado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'aplicado',
            'aplicado_at' => now(),
        ]);
    }

    public function anulado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'anulado',
            'anulado_at' => now(),
            'motivo_anulacion' => 'Anulado en prueba automatizada',
        ]);
    }
}
