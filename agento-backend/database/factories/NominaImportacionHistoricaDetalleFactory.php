<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NominaImportacionHistoricaDetalle>
 *
 * `importacion_id` y `empresa_id` se generan por separado por defecto (cada
 * uno con su propia Empresa) — en una prueba que necesite que ambos
 * pertenezcan a la misma empresa, pásalos explícitamente:
 * `NominaImportacionHistoricaDetalle::factory()->for($importacion, 'importacion')
 *     ->state(['empresa_id' => $importacion->empresa_id])`.
 */
class NominaImportacionHistoricaDetalleFactory extends Factory
{
    protected $model = NominaImportacionHistoricaDetalle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'importacion_id' => NominaImportacionHistorica::factory(),
            'empresa_id' => Empresa::factory(),
            'hoja_nombre' => 'Hoja1',
            'fila_numero' => fake()->unique()->numberBetween(2, 1000000),
            'datos_originales' => ['documento' => fake()->unique()->numerify('########')],
            'tipo_documento_normalizado' => 'dni',
            'numero_documento_normalizado' => fake()->unique()->numerify('########'),
            'colaborador_id' => null,
            'tipo_antecedente' => 'gratificacion_pagada',
            'estado_validacion' => 'pendiente',
            'fingerprint_negocio' => null,
        ];
    }

    public function aplicado(): static
    {
        return $this->state(fn (array $attributes) => ['estado_validacion' => 'aplicado']);
    }
}
