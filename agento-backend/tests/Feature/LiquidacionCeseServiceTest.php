<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Cese y liquidación separados: el cese no crea liquidación y el sueldo del
 * mes lo paga la boleta; la liquidación se registra aparte (asistida o manual).
 */
class LiquidacionCeseServiceTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function cesado(?string $fechaCese = null)
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);

        return app(ColaboradorService::class)->cesar($colaborador->empresa, $colaborador, $fechaCese ?? now()->toDateString(), 'Renuncia voluntaria');
    }

    private function servicio(): LiquidacionCeseService
    {
        return app(LiquidacionCeseService::class);
    }

    private function lineasDe(array $propuesta): array
    {
        return collect($propuesta['conceptos'])->map(fn ($c) => ['codigo' => $c['codigo'], 'monto' => $c['monto']])->all();
    }

    public function test_cesar_no_crea_liquidacion_y_el_colaborador_queda_pendiente(): void
    {
        $colaborador = $this->cesado();

        $this->assertFalse($colaborador->activo);
        $this->assertSame(0, LiquidacionCese::where('colaborador_id', $colaborador->id)->count());
        $this->assertTrue($this->servicio()->pendientes($colaborador->empresa)->contains('id', $colaborador->id));
    }

    public function test_cesar_bloquea_un_segundo_cese_sobre_el_mismo_colaborador(): void
    {
        $colaborador = $this->cesado();

        $this->expectException(ValidationException::class);
        app(ColaboradorService::class)->cesar($colaborador->empresa, $colaborador->fresh(), now()->toDateString(), 'Renuncia');
    }

    public function test_la_boleta_del_mes_paga_hasta_la_fecha_de_cese(): void
    {
        $colaborador = $this->cesado(now()->startOfMonth()->addDays(14)->toDateString());

        $calculo = app(CalcularBoletaColaborador::class)->calcular(
            $colaborador->fresh(), now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), now()->endOfMonth()->toDateString(),
        );

        $this->assertEquals(15, $calculo['dias_pagados']);
        $basico = collect($calculo['ingresos'])->firstWhere('codigo', 'SUELDO_BASICO');
        $this->assertEqualsWithDelta(1500.0, (float) $basico['monto'], 0.01);
    }

    public function test_el_cesado_entra_a_la_planilla_salvo_que_su_mes_se_haya_pagado_en_una_liquidacion_anterior(): void
    {
        $colaborador = $this->cesado(now()->startOfMonth()->addDays(9)->toDateString());
        $planilla = fn () => collect(app(BoletaService::class)->previsualizarPlanilla(
            $colaborador->empresa, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), now()->endOfMonth()->toDateString(),
        ))->pluck('colaborador_id');

        $this->assertTrue($planilla()->contains($colaborador->id));

        LiquidacionCese::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha_cese' => $colaborador->fecha_cese, 'motivo_cese' => 'Renuncia', 'remuneracion_snapshot' => 3000,
            'regimen_laboral_snapshot' => 'General', 'modo' => 'legado', 'incluir_remuneracion' => true, 'estado' => 'calculada',
        ]);

        $this->assertFalse($planilla()->contains($colaborador->id));
    }

    public function test_la_propuesta_no_incluye_el_sueldo_del_mes(): void
    {
        $colaborador = $this->cesado();
        $propuesta = $this->servicio()->propuesta($colaborador->empresa, $colaborador);

        $codigos = collect($propuesta['conceptos'])->pluck('codigo');
        $this->assertFalse($codigos->contains('REMUNERACION_CESE'));
        $this->assertTrue($codigos->contains('CTS_TRUNCA'));
        $this->assertTrue($codigos->contains('VACACIONES_TRUNCAS'));
    }

    public function test_registrar_asistida_sin_cambios_marca_las_lineas_como_calculadas(): void
    {
        $colaborador = $this->cesado();
        $usuarioId = User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        $propuesta = $this->servicio()->propuesta($colaborador->empresa, $colaborador);

        $liquidacion = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'asistido', $this->lineasDe($propuesta), null, $usuarioId);

        $this->assertSame('asistido', $liquidacion->modo);
        $this->assertSame('calculada', $liquidacion->estado);
        $this->assertEquals($propuesta['neto_pagar'], (float) $liquidacion->neto_pagar);
        $this->assertTrue($liquidacion->conceptos->every(fn ($c) => $c->origen === 'calculado'));
    }

    public function test_ajustar_una_linea_exige_motivo_y_conserva_el_monto_calculado(): void
    {
        $colaborador = $this->cesado();
        $usuarioId = User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        $propuesta = $this->servicio()->propuesta($colaborador->empresa, $colaborador);
        $lineas = collect($this->lineasDe($propuesta))->map(fn ($l) => $l['codigo'] === 'CTS_TRUNCA' ? [...$l, 'monto' => 999.99] : $l)->all();

        try {
            $this->servicio()->registrar($colaborador->empresa, $colaborador, 'asistido', $lineas, null, $usuarioId);
            $this->fail('Un ajuste sin motivo debe rechazarse.');
        } catch (ValidationException) {
        }

        $lineas = collect($lineas)->map(fn ($l) => $l['codigo'] === 'CTS_TRUNCA' ? [...$l, 'motivo_ajuste' => 'CTS depositada parcialmente'] : $l)->all();
        $liquidacion = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'asistido', $lineas, null, $usuarioId);

        $cts = $liquidacion->conceptos->firstWhere('codigo', 'CTS_TRUNCA');
        $this->assertSame('ajustado', $cts->origen);
        $this->assertEquals(999.99, (float) $cts->monto);
        $this->assertEquals(collect($propuesta['conceptos'])->firstWhere('codigo', 'CTS_TRUNCA')['monto'], (float) $cts->monto_calculado);
    }

    public function test_liquidacion_manual_exige_sustento_y_registra_las_lineas(): void
    {
        $colaborador = $this->cesado();
        $usuarioId = User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        $lineas = [
            ['codigo' => 'CTS_TRUNCA', 'monto' => 1200],
            ['codigo' => 'VACACIONES_TRUNCAS', 'monto' => 800],
            ['codigo' => 'ONP', 'monto' => 104],
        ];

        try {
            $this->servicio()->registrar($colaborador->empresa, $colaborador, 'manual', $lineas, null, $usuarioId);
            $this->fail('Una liquidación manual sin sustento debe rechazarse.');
        } catch (ValidationException) {
        }

        $liquidacion = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'manual', $lineas, 'Cálculo del contador (Excel octubre)', $usuarioId);

        $this->assertSame('manual', $liquidacion->modo);
        $this->assertEquals(2000, (float) $liquidacion->total_ingresos);
        $this->assertEquals(1896, (float) $liquidacion->neto_pagar);
        $this->assertTrue($liquidacion->conceptos->every(fn ($c) => $c->origen === 'manual'));
        $this->assertFalse($this->servicio()->pendientes($colaborador->empresa)->contains('id', $colaborador->id));
    }

    public function test_registrar_de_nuevo_reemplaza_la_calculada_como_nueva_version(): void
    {
        $colaborador = $this->cesado();
        $usuarioId = User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        $lineas = [['codigo' => 'CTS_TRUNCA', 'monto' => 1000]];

        $primera = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'manual', $lineas, 'Primer cálculo', $usuarioId);
        $segunda = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'manual', $lineas, 'Corrección', $usuarioId);

        $this->assertFalse($primera->fresh()->es_version_vigente);
        $this->assertSame(2, $segunda->version);
        $this->assertTrue($segunda->es_version_vigente);
    }

    public function test_los_movimientos_de_vacaciones_ajustan_la_propuesta(): void
    {
        $colaborador = $this->cesado();
        $sinAjuste = collect($this->servicio()->propuesta($colaborador->empresa, $colaborador)['conceptos']);

        VacacionMovimiento::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha' => $colaborador->fecha_ingreso, 'tipo' => 'ajuste', 'dias' => 10,
            'descripcion' => 'Saldo trasladado del sistema anterior',
        ]);
        $conAjuste = collect($this->servicio()->propuesta($colaborador->empresa, $colaborador)['conceptos']);

        $vacaciones = fn ($conceptos) => $conceptos->whereIn('codigo', ['VACACIONES_TRUNCAS', 'VACACIONES_PENDIENTES'])->sum('monto');
        // +10 días a (sueldo / 30) por día, sin asignación familiar en el fixture.
        $this->assertEqualsWithDelta(1000.0, $vacaciones($conAjuste) - $vacaciones($sinAjuste), 0.02);
    }

    public function test_sin_remuneracion_registrada_se_completa_el_sueldo_y_el_sistema_calcula(): void
    {
        $colaborador = $this->cesado();
        $usuarioId = User::factory()->create(['empresa_id' => $colaborador->empresa_id])->id;
        ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)->delete();

        $sinDatos = $this->servicio()->propuesta($colaborador->empresa, $colaborador);
        $this->assertFalse($sinDatos['puede_calcular']);
        $this->assertSame('faltante', $sinDatos['datos_calculo']['sueldo']['estado']);

        $datos = ['sueldo' => 2500, 'fecha_ingreso' => $colaborador->fecha_ingreso->copy()->subYear()->toDateString()];
        $conDatos = $this->servicio()->propuesta($colaborador->empresa, $colaborador, $datos);
        $this->assertTrue($conDatos['puede_calcular']);
        $this->assertSame('manual', $conDatos['datos_calculo']['sueldo']['origen']);
        $this->assertSame('manual', $conDatos['datos_calculo']['fecha_ingreso']['origen']);
        $this->assertGreaterThan(0, $conDatos['total_ingresos']);

        try {
            $this->servicio()->registrar($colaborador->empresa, $colaborador, 'asistido', $this->lineasDe($conDatos), null, $usuarioId, $datos);
            $this->fail('Datos ingresados a mano sin sustento deben rechazarse.');
        } catch (ValidationException) {
        }

        $liquidacion = $this->servicio()->registrar($colaborador->empresa, $colaborador, 'asistido', $this->lineasDe($conDatos), 'Sueldo y fecha de ingreso según contrato físico', $usuarioId, $datos);

        $this->assertEquals(2500, (float) $liquidacion->remuneracion_snapshot);
        $this->assertSame('manual', $liquidacion->datos_calculo['sueldo']['origen']);
        $this->assertSame('sistema', $liquidacion->datos_calculo['asignacion_familiar']['origen']);
    }

    public function test_sin_cese_registrado_no_se_puede_liquidar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();

        $this->expectException(ValidationException::class);
        $this->servicio()->propuesta($colaborador->empresa, $colaborador);
    }
}
