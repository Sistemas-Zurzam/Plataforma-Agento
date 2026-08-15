<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => fake()->unique()->jobTitle(),
        ];
    }
}
