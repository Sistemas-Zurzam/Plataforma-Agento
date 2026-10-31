<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class BusquedaColaboradorAsistenciaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_busqueda_por_nombre_completo_cruza_nombres_y_apellidos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $zandro = $this->crearColaborador(atributos: ['nombres' => 'ZANDRO JAVIER', 'apellidos' => 'IZQUIERDO CRISTANCHO']);
        $javier = $this->crearColaborador(atributos: ['nombres' => 'JAVIER', 'apellidos' => 'ROJAS']);
        // Acotado a los dos creados: el seeder puede traer otros colaboradores.
        $buscar = fn (string $texto) => Colaborador::whereKey([$zandro->id, $javier->id])->buscarTexto($texto)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$zandro->id], $buscar('zandro javier izquierdo'));
        $this->assertSame([$zandro->id], $buscar('  Zandro   Cristancho '));
        $this->assertSame([$zandro->id, $javier->id], $buscar('javier'));
    }

    public function test_opciones_del_filtro_incluyen_horas_extra_fuera_de_las_primeras_filas(): void
    {
        $this->seed(DatabaseSeeder::class);
        $antiguo = $this->crearColaborador(atributos: ['nombres' => 'ZANDRO', 'apellidos' => 'IZQUIERDO']);
        $reciente = $this->crearColaborador(atributos: ['nombres' => 'MARIA', 'apellidos' => 'YARE']);
        $ajeno = $this->crearColaborador(Empresa::factory()->create(), ['nombres' => 'OTRA', 'apellidos' => 'EMPRESA']);
        $this->horaExtra($antiguo, '2026-10-02');
        $this->horaExtra($reciente, '2026-10-28');
        $this->horaExtra($ajeno, '2026-10-10');

        $opciones = app(AsistenciaOperacionService::class)->colaboradoresConHorasExtra($antiguo->empresa, [
            'fecha_desde' => '2026-10-01', 'fecha_hasta' => '2026-10-31',
        ]);

        $this->assertSame([$reciente->id, $antiguo->id], $opciones->pluck('value')->all());
        $this->assertSame('ZANDRO IZQUIERDO', $opciones->firstWhere('value', $antiguo->id)['label']);
    }

    private function horaExtra(Colaborador $colaborador, string $fecha): void
    {
        AsistenciaHoraExtra::create([
            'empresa_id' => $colaborador->empresa_id,
            'colaborador_id' => $colaborador->id,
            'fecha' => $fecha,
            'tasa' => '25',
            'minutos_observados' => 120,
            'minutos_solicitados' => 120,
            'minutos_aprobados' => 0,
            'estado' => 'pendiente',
        ]);
    }
}
