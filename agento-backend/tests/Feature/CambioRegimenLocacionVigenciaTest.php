<?php

namespace Tests\Feature;

use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class CambioRegimenLocacionVigenciaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_el_cambio_a_locacion_respeta_la_vigencia_indicada(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'fecha_ingreso' => '2026-05-02',
            'regimen_laboral' => 'Micro Empresa',
            'tipo_contrato' => 'plazo_fijo',
        ]);

        app(ColaboradorService::class)->actualizarConfiguracionNomina(
            $colaborador->empresa,
            $colaborador,
            ['regimen_laboral' => 'Locacion de Servicios', 'sistema_previsional' => null],
            '2026-09-01',
        );

        $condicion = $colaborador->fresh()->condicionesLaborales()
            ->orderByDesc('vigencia_desde')->orderByDesc('id')->firstOrFail();
        $this->assertSame('Locacion de Servicios', $condicion->regimen_laboral);
        $this->assertSame('2026-09-01', $condicion->vigencia_desde->toDateString());
    }
}
