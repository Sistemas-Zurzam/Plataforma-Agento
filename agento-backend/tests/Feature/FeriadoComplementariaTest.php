<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class FeriadoComplementariaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $banco = Banco::firstOrCreate(['codigo' => 'bcp'], ['nombre' => 'BCP', 'activo' => true]);
        $c = $this->crearColaborador($empresa, ['tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios',
            'fecha_ingreso' => '2026-01-01', 'numero_documento' => '87654321', 'banco_id' => $banco->id,
            'numero_cuenta' => '19123456789012', 'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN']);
        $c->remuneraciones()->update(['salario' => 5000]);
        $ciclo = CicloRemunerativo::create(['empresa_id' => $empresa->id, 'nombre' => 'Agosto', 'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado']);
        $b = Boleta::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $c->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 5000, 'dias_pagados' => 30,
            'total_ingresos' => 5000, 'total_egresos' => 907.29, 'total_aportaciones' => 0, 'neto_a_pagar' => 4092.71,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now()]);
        foreach (['HONORARIO_BRUTO' => ['ingreso', 5000], 'RETENCION_RENTA_4TA' => ['egreso', 400],
            'DESCUENTO_TARDANZA' => ['egreso', 7.29], 'ADELANTO_SUELDO' => ['egreso', 500]] as $codigo => [$tipo, $monto]) {
            $b->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                'tipo' => $tipo, 'monto' => $monto, 'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => false]);
        }
        return [$empresa, $ciclo, $b, $usuario, app(PlanillaComplementariaService::class)];
    }

    public function test_calendario_incluye_feriados_del_ciclo_y_anteriores_pero_no_futuros(): void
    {
        [$empresa, $ciclo, , , $service] = $this->escenario();
        $fechas = $service->feriadosDisponibles($empresa, $ciclo);
        $this->assertContains('2026-08-06', $fechas);
        $this->assertContains('2026-08-30', $fechas);
        $this->assertContains('2026-07-28', $fechas);
        $this->assertNotContains('2026-10-08', $fechas);
        $this->assertNotContains('2026-08-07', $fechas);
    }

    public function test_iraydi_feriado_en_mismo_ciclo_preserva_boleta_y_exporta_adicional(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $item = $service->crearRegularizacionFeriadoHistorico($empresa, $ciclo, [$boleta->id], '2026-08-06', 'Asistió de 06:00 a 14:00; adicional confirmado', $usuario->id);
        $d = $item->detalles->first();
        // Locador (Locacion de Servicios): sueldo/30×1, no ×2 — no hay
        // sobretasa de ley del Art. 8° D.Leg. 713 porque no hay relación
        // laboral (ver PlanillaComplementariaService::crearFeriadoBloqueado).
        $this->assertSame('166.67', $d->diferencia_ingresos);
        $this->assertSame('166.67', $d->diferencia_neta);
        $this->assertSame('4259.38', $d->neto_recalculado);
        $this->assertSame('4092.71', $boleta->fresh()->neto_a_pagar);
        $this->assertEquals(907.29, $d->calculo_snapshot['total_egresos']);
        $this->assertSame('2026-08-06', $d->calculo_snapshot['feriado_regularizado']['fecha']);
        $service->aprobar($empresa, $item, $usuario->id);
        $cuenta = new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']);
        $lineas = explode("\r\n", trim($service->exportarBcp($empresa, $item, $cuenta, '2026-09-06', '4')));
        $this->assertSame('00000000000166.67', substr($lineas[1], 177, 17));
        $service->marcarPagada($empresa, $item, $usuario->id, 'TEST');
        $this->expectException(ValidationException::class);
        $service->crearRegularizacionFeriadoHistorico($empresa, $ciclo, [$boleta->id], '2026-08-06', 'Duplicado', $usuario->id);
    }

    public function test_usa_condicion_historica_de_honorarios_aunque_el_ciclo_receptor_tenga_snapshot_de_planilla(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $colaborador = $boleta->colaborador;
        $colaborador->remuneraciones()->update(['salario' => 2500]);
        $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => 'Locacion de Servicios',
            'tipo_contrato' => 'locacion_servicios',
            'vigencia_desde' => '2026-01-01',
        ]);
        $boleta->update(['regimen_laboral_snapshot' => 'Micro Empresa']);

        $item = $service->crearRegularizacionFeriadoHistorico(
            $empresa,
            $ciclo,
            [$boleta->id],
            '2026-07-29',
            'Honorario histórico',
            $usuario->id,
        );

        $detalle = $item->detalles->first();
        $this->assertSame('83.33', $detalle->diferencia_ingresos);
        $this->assertSame('83.33', $detalle->diferencia_neta);
        $this->assertSame('honorarios', $detalle->calculo_snapshot['feriado_regularizado']['tipo_pago']);
        $this->assertSame(1, $detalle->calculo_snapshot['feriado_regularizado']['multiplicador']);
    }

    public function test_otra_fecha_parte_del_ultimo_neto_pagado(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $primera = $service->crearRegularizacionFeriadoHistorico($empresa, $ciclo, [$boleta->id], '2026-07-28', 'Anterior', $usuario->id);
        $service->aprobar($empresa, $primera, $usuario->id);
        $service->marcarPagada($empresa, $primera, $usuario->id, 'TEST');
        $segunda = $service->crearRegularizacionFeriadoHistorico($empresa, $ciclo, [$boleta->id], '2026-08-06', 'Actual', $usuario->id);
        $this->assertSame('4259.38', $segunda->detalles->first()->neto_original);
        $this->assertSame('4426.05', $segunda->detalles->first()->neto_recalculado);
        $this->assertSame('166.67', $segunda->detalles->first()->diferencia_neta);
    }

    public function test_http_exige_confirmacion_y_rechaza_fecha_futura(): void
    {
        [$empresa, $ciclo, $b, $usuario] = $this->escenario();
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)]);
        $url = "/api/ciclos-remunerativos/{$ciclo->id}/complementarias/regularizacion-feriado-historico";
        $datos = ['boleta_ids' => [$b->id], 'fecha_feriado' => '2026-08-06', 'motivo' => 'Test', 'sin_descanso_sustitutorio' => true];
        $this->postJson($url, $datos)->assertUnprocessable()->assertJsonValidationErrors('sin_pago_previo');
        $this->postJson($url, [...$datos, 'sin_pago_previo' => true, 'fecha_feriado' => '2026-10-08'])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'sin_pago_previo' => true])->assertCreated()->assertJsonPath('data.total_a_pagar', '166.67');
    }
}
