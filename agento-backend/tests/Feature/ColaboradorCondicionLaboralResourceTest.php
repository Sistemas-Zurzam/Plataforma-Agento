<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Http\Resources\ColaboradorResource;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ColaboradorCondicionLaboralResourceTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_expone_la_vigencia_real_de_la_condicion_laboral(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2026-08-05',
            'categoria_trabajador' => 'empleado',
        ]);
        $colaborador->condicionesLaborales()->delete();
        $condicion = $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => $colaborador->regimen_laboral,
            'tipo_contrato' => $colaborador->tipo_contrato,
            'categoria_trabajador' => 'empleado',
            'vigencia_desde' => '2026-08-05',
        ]);

        $detalle = app(ColaboradorService::class)->obtenerDetalle($empresa, $colaborador);
        $datos = (new ColaboradorResource($detalle))->resolve();

        $this->assertSame($condicion->id, $datos['condicion_laboral']['id']);
        $this->assertSame('2026-08-05', $datos['condicion_laboral']['vigencia_desde']);
    }
}
