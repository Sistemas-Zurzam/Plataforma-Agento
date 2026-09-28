<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Models\Empresa;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class CompletarCategoriaCondicionesLaboralesTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_completa_categorias_nulas_sin_cambiar_vigencias_ni_valores_existentes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'tipo_trabajador' => 'trabajador',
            'categoria_trabajador' => 'empleado',
            'fecha_ingreso' => '2026-01-01',
        ]);
        $colaborador->condicionesLaborales()->delete();
        $nula = $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => 'Pequena Empresa',
            'tipo_contrato' => 'plazo_fijo',
            'categoria_trabajador' => null,
            'vigencia_desde' => '2026-01-01',
        ]);
        $historica = $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => 'Pequena Empresa',
            'tipo_contrato' => 'plazo_fijo',
            'categoria_trabajador' => 'obrero',
            'vigencia_desde' => '2026-07-01',
        ]);

        $migracion = require database_path('migrations/2026_09_17_000118_completar_categoria_condiciones_laborales.php');
        $migracion->up();

        $this->assertSame('empleado', $nula->fresh()->categoria_trabajador);
        $this->assertSame('2026-01-01', $nula->fresh()->vigencia_desde->toDateString());
        $this->assertSame('obrero', $historica->fresh()->categoria_trabajador);
        $this->assertSame('2026-07-01', $historica->fresh()->vigencia_desde->toDateString());
    }
}
