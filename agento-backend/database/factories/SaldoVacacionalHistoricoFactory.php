<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\SaldoVacacionalHistorico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaldoVacacionalHistorico>
 *
 * `colaborador_id` es obligatorio y no tiene valor por defecto — ver nota
 * en BeneficioSocialHistoricoFactory.
 */
class SaldoVacacionalHistoricoFactory extends Factory
{
    protected $model = SaldoVacacionalHistorico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'importacion_detalle_id' => null,
            'fecha_ingreso_vinculo' => '2025-01-02',
            'fecha_fin_vinculo' => null,
            'fecha_corte' => '2026-07-31',
            'dias_devengados' => 15,
            'dias_gozados' => 5,
            'dias_pagados' => 0,
            'dias_pendientes' => 10,
            'estado' => 'borrador',
            'origen' => 'excel_historico',
        ];
    }

    public function aprobado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'aprobado',
            'aprobado_at' => now(),
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
