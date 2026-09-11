<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeneficioSocialHistorico>
 *
 * `colaborador_id` es obligatorio y no tiene valor por defecto: este
 * proyecto no tiene todavía una ColaboradorFactory (los colaboradores de
 * prueba se crean con `Tests\Concerns\CreaColaboradorDePrueba`), así que
 * cada prueba debe pasarlo explícitamente vía `state()`.
 */
class BeneficioSocialHistoricoFactory extends Factory
{
    protected $model = BeneficioSocialHistorico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'importacion_detalle_id' => null,
            'tipo' => 'gratificacion_julio',
            'anio' => 2026,
            'periodo' => '2026-S1',
            'fecha_periodo_inicio' => '2026-01-01',
            'fecha_periodo_fin' => '2026-06-30',
            'fecha_pago_deposito' => '2026-07-15',
            'importe_bruto' => 1500,
            'importe_pagado' => null,
            'estado' => 'borrador',
            'version' => 1,
            'es_version_vigente' => true,
            'fecha_ingreso_vinculo' => '2025-01-02',
            'fecha_fin_vinculo' => null,
            'fecha_corte' => '2026-07-31',
            'origen' => 'excel_historico',
        ];
    }

    public function pagado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pagado',
            'importe_pagado' => $attributes['importe_bruto'] ?? 1500,
        ]);
    }

    public function anulado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'anulado',
            'es_version_vigente' => false,
            'anulado_at' => now(),
            'motivo_anulacion' => 'Anulado en prueba automatizada',
        ]);
    }

    /**
     * Construye la versión N+1 de un beneficio ya anulado: misma clave de
     * negocio (vínculo+tipo+año+periodo), pero con `version` incrementada
     * y `es_version_vigente=true` — la que el índice único permite crear
     * después de que la anterior quedó anulada (`es_version_vigente=false`).
     */
    public function nuevaVersionDe(BeneficioSocialHistorico $anterior): static
    {
        return $this->state(fn () => [
            'empresa_id' => $anterior->empresa_id, 'colaborador_id' => $anterior->colaborador_id,
            'fecha_ingreso_vinculo' => $anterior->fecha_ingreso_vinculo,
            'tipo' => $anterior->tipo, 'anio' => $anterior->anio, 'periodo' => $anterior->periodo,
            'version' => $anterior->version + 1, 'es_version_vigente' => true,
        ]);
    }
}
