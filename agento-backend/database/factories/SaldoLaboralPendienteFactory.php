<?php

namespace Database\Factories;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\SaldoLaboralPendiente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaldoLaboralPendiente>
 *
 * `colaborador_id` es obligatorio y no tiene valor por defecto — ver nota
 * en BeneficioSocialHistoricoFactory. `saldo_pendiente` NUNCA debe pasarse:
 * es una columna generada por la base de datos (importe_original -
 * importe_aplicado) y no forma parte de los atributos asignables en masa.
 */
class SaldoLaboralPendienteFactory extends Factory
{
    protected $model = SaldoLaboralPendiente::class;

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
            'tipo' => 'prestamo',
            'descripcion' => 'Préstamo personal otorgado antes de agosto de 2026',
            'importe_original' => 1000,
            'importe_aplicado' => 0,
            'fecha_corte' => '2026-07-31',
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
