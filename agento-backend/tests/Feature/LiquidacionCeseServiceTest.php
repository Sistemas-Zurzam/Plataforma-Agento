<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class LiquidacionCeseServiceTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_la_seleccion_excluye_beneficios_del_neto_sin_ocultarlos_del_detalle(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);

        $resultado = app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa,
            $colaborador,
            now()->toDateString(),
            ['incluir_remuneracion' => true, 'incluir_cts' => false, 'incluir_gratificacion' => false, 'incluir_vacaciones' => false],
        );

        $remuneracion = collect($resultado['conceptos'])->firstWhere('codigo', 'REMUNERACION_CESE');
        $cts = collect($resultado['conceptos'])->firstWhere('codigo', 'CTS_TRUNCA');

        $this->assertTrue($remuneracion['incluido']);
        $this->assertFalse($cts['incluido']);
        // Comparar contra total_ingresos, no contra neto_pagar: incluir la
        // remuneración también arrastra su descuento previsional (ONP/AFP)
        // como egreso incluido — no se puede pagar un sueldo sin retener su
        // aporte. neto_pagar legítimamente queda por debajo del ingreso
        // bruto; lo que debe probarse acá es que CTS no aportó al ingreso.
        $this->assertSame($remuneracion['monto'], $resultado['total_ingresos']);
    }

    public function test_rechaza_fecha_anterior_al_ingreso(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa,
            $colaborador,
            $colaborador->fecha_ingreso->copy()->subDay()->toDateString(),
        );
    }

    public function test_guardar_persiste_la_liquidacion_y_sus_conceptos_incluidos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);
        $usuarioId = \App\Models\User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;

        $liquidacion = app(LiquidacionCeseService::class)->guardar(
            $colaborador->empresa, $colaborador, now()->toDateString(), 'Renuncia voluntaria',
            ['incluir_remuneracion' => true, 'incluir_cts' => false, 'incluir_gratificacion' => false, 'incluir_vacaciones' => false],
            $usuarioId,
        );

        $this->assertDatabaseHas('liquidaciones_cese', [
            'id' => $liquidacion->id, 'colaborador_id' => $colaborador->id,
            'estado' => 'calculada', 'es_version_vigente' => true, 'version' => 1,
        ]);
        // Solo se persisten los conceptos incluidos: REMUNERACION_CESE y sus
        // egresos asociados (tardanza, ONP) viajan juntos porque incluir la
        // remuneración implica incluir su descuento previsional — los
        // excluidos (CTS, gratificación, vacaciones) no deben aparecer.
        $this->assertEqualsCanonicalizing(
            ['REMUNERACION_CESE', 'DESCUENTO_TARDANZA', 'ONP'],
            $liquidacion->conceptos->pluck('codigo')->all(),
        );
    }

    public function test_cesar_bloquea_un_segundo_cese_sobre_el_mismo_colaborador(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);
        $usuarioId = \App\Models\User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        $seleccion = ['incluir_remuneracion' => true, 'incluir_cts' => false, 'incluir_gratificacion' => false, 'incluir_vacaciones' => false];

        $colaborador = app(ColaboradorService::class)->cesar($colaborador->empresa, $colaborador, now()->toDateString(), 'Renuncia', $seleccion, $usuarioId);

        $this->assertFalse($colaborador->activo);
        $this->assertSame(1, LiquidacionCese::where('colaborador_id', $colaborador->id)->count());

        $this->expectException(ValidationException::class);
        app(ColaboradorService::class)->cesar($colaborador->empresa, $colaborador->fresh(), now()->toDateString(), 'Renuncia', $seleccion, $usuarioId);
    }

    public function test_los_movimientos_de_vacaciones_ajustan_el_saldo_de_la_liquidacion(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);

        $sinAjuste = app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa, $colaborador, now()->toDateString(),
            ['incluir_remuneracion' => false, 'incluir_cts' => false, 'incluir_gratificacion' => false, 'incluir_vacaciones' => true],
        );
        $vacacionesSinAjuste = collect($sinAjuste['conceptos'])->firstWhere('codigo', 'VACACIONES_TRUNCAS');

        VacacionMovimiento::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha' => $colaborador->fecha_ingreso, 'tipo' => 'ajuste', 'dias' => 10,
            'descripcion' => 'Saldo trasladado del sistema anterior',
        ]);

        $conAjuste = app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa, $colaborador, now()->toDateString(),
            ['incluir_remuneracion' => false, 'incluir_cts' => false, 'incluir_gratificacion' => false, 'incluir_vacaciones' => true],
        );
        $vacacionesConAjuste = collect($conAjuste['conceptos'])->firstWhere('codigo', 'VACACIONES_TRUNCAS');

        // +10 días de ajuste a (sueldo/30) por día deben reflejarse íntegros
        // en el monto — el kardex manual no se recorta ni se prorratea.
        $diferenciaEsperada = round(($colaborador->remuneracionVigente->salario / 30) * 10, 2);
        $this->assertEqualsWithDelta($diferenciaEsperada, $vacacionesConAjuste['monto'] - $vacacionesSinAjuste['monto'], 0.01);
    }
}
