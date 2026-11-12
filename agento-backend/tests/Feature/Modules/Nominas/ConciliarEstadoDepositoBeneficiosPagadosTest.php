<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Migración 2026_11_13_000002: los beneficios pagados antes del registro de
 * depósitos dejan de verse como "pendiente de depósito"; los calculados sin
 * pagar y los que ya tienen depósitos no se tocan.
 */
class ConciliarEstadoDepositoBeneficiosPagadosTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function detalle(Empresa $empresa, string $tipo, string $estado, int $anio = 2026): BeneficioSocialDetalle
    {
        $beneficio = BeneficioSocial::create(['empresa_id' => $empresa->id, 'tipo' => $tipo, 'anio' => $anio, 'version' => 1,
            'es_version_vigente' => true, 'total_colaboradores' => 1, 'total_bruto' => 1500, 'total_neto' => 1500,
            'estado' => $estado, 'calculado_at' => now()]);

        return BeneficioSocialDetalle::create(['beneficio_social_id' => $beneficio->id, 'colaborador_id' => $this->crearColaborador($empresa)->id,
            'sueldo_basico' => 3000, 'meses' => 6, 'bruta' => 1500, 'neta' => 1500, 'bonificacion_extraordinaria' => 0]);
    }

    public function test_solo_concilia_beneficios_pagados_sin_depositos(): void
    {
        $empresa = Empresa::factory()->create();
        $pagadoCts = $this->detalle($empresa, 'cts_mayo', 'pagado');
        $pagadoGrati = $this->detalle($empresa, 'gratificacion_julio', 'pagado');
        $calculado = $this->detalle($empresa, 'cts_noviembre', 'calculado');
        $conDeposito = $this->detalle($empresa, 'cts_mayo', 'pagado', 2025);
        $conDeposito->update(['estado_deposito' => 'depositado_parcial']);
        BeneficioSocialDeposito::create(['beneficio_social_detalle_id' => $conDeposito->id, 'fecha_deposito' => '2026-05-15', 'importe' => 500,
            'entidad' => 'Banco ficticio', 'referencia' => 'OP-1', 'idempotencia' => str_repeat('a', 64)]);

        (require database_path('migrations/2026_11_13_000002_conciliar_estado_deposito_beneficios_pagados.php'))->up();

        $this->assertSame('conciliado', $pagadoCts->fresh()->estado_deposito);
        $this->assertSame('conciliado', $pagadoGrati->fresh()->estado_deposito);
        $this->assertSame('pendiente_deposito', $calculado->fresh()->estado_deposito);
        $this->assertSame('depositado_parcial', $conDeposito->fresh()->estado_deposito);
    }
}
