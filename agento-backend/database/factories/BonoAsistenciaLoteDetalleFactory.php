<?php

namespace Database\Factories;

use App\Modules\Nominas\Models\BonoAsistenciaLoteDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonoAsistenciaLoteDetalle>
 *
 * bono_asistencia_lote_id y colaborador_id no tienen valor por defecto —
 * pásalos explícitamente al crear, ej.:
 * BonoAsistenciaLoteDetalle::factory()->create(['bono_asistencia_lote_id' => $lote->id, 'colaborador_id' => $colaborador->id]).
 */
class BonoAsistenciaLoteDetalleFactory extends Factory
{
    protected $model = BonoAsistenciaLoteDetalle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documento_snapshot' => fake()->unique()->numerify('########'),
            'colaborador_nombre_snapshot' => fake()->name(),
            'dias_falta_justificada' => 0,
            'dias_falta_injustificada' => 0,
            'tardanzas' => 0,
            'bono_base' => 150,
            'porcentaje_propuesto' => 100,
            'monto_propuesto' => 150,
        ];
    }
}
