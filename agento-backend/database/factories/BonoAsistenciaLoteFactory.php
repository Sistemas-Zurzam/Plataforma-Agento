<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BonoAsistenciaLote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonoAsistenciaLote>
 *
 * ciclo_id y concepto_id no tienen factory propia en el proyecto (se crean
 * con ::create()/seeders en los tests, nunca con ::factory()) — hay que
 * pasarlos explícitamente al usar esta factory, ej.:
 * BonoAsistenciaLote::factory()->create(['ciclo_id' => $ciclo->id, 'concepto_id' => $concepto->id]).
 */
class BonoAsistenciaLoteFactory extends Factory
{
    protected $model = BonoAsistenciaLote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => 'Bono de asistencia '.fake()->monthName().' '.fake()->year(),
            'estado' => 'borrador',
        ];
    }

    public function exportado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'exportado',
            'archivo_exportado_nombre' => 'bono-asistencia-'.fake()->unique()->numerify('####').'.xlsx',
            'exportado_en' => now(),
        ]);
    }

    public function aplicado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'aplicado',
            'aplicado_en' => now(),
        ]);
    }
}
