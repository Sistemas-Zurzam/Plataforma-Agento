<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\SunatMapeoLookup;
use App\Modules\Nominas\Infrastructure\Plame\Export\JorGenerator;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\SunatMapeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class JorGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_usa_dias_pagados_si_no_hay_marcaciones_y_suma_horas_extra_complementarias(): void
    {
        $this->seed(SunatMapeoSeeder::class);
        $colaborador = new Colaborador(['tipo_documento' => 'dni', 'numero_documento' => '76851073']);
        $colaborador->id = 70;
        $boleta = new Boleta(['dias_pagados' => 30]);
        $boleta->setAttribute('minutos_extra_complementaria', 90);
        $boleta->setRelation('colaborador', $colaborador);
        $ciclo = new CicloRemunerativo(['fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31']);
        $asistencia = Mockery::mock(AsistenciaOperacionService::class);
        $asistencia->shouldReceive('horasConsolidadasPorColaborador')->once()->andReturn([
            'minutos_ordinarios' => 0,
            'minutos_extra_25' => 0,
            'minutos_extra_35' => 0,
            'minutos_extra_100' => 0,
            'minutos_extra_total' => 0,
        ]);
        $contexto = new PlameExportContext(
            new Empresa(), $ciclo, collect([$boleta]), collect(), SunatMapeoLookup::cargar(),
        );

        $filas = (new JorGenerator($asistencia))->generar($contexto);

        $this->assertSame([['01', '76851073', '240', '0', '1', '30']], $filas);
    }
}
