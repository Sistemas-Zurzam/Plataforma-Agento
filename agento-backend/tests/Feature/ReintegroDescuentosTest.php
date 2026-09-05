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

class ReintegroDescuentosTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $banco = Banco::firstOrCreate(['codigo' => 'bcp'], ['nombre' => 'BCP', 'activo' => true]);
        $colaborador = $this->crearColaborador($empresa, [
            'tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios',
            'numero_documento' => '87654321', 'banco_id' => $banco->id, 'numero_cuenta' => '19123456789012',
            'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN',
        ]);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026', 'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 1400, 'dias_pagados' => 30,
            'total_ingresos' => 1788.27, 'total_egresos' => 399.66, 'total_aportaciones' => 0, 'neto_a_pagar' => 1388.61,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        foreach (['DESCUENTO_FALTA' => 46.67, 'DESCUENTO_TARDANZA' => 52.99, 'ADELANTO_SUELDO' => 300] as $codigo => $monto) {
            $boleta->conceptos()->create([
                'concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                'tipo' => 'egreso', 'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => false, 'monto' => $monto,
            ]);
        }
        return [$empresa, $ciclo, $boleta, $usuario->id, app(PlanillaComplementariaService::class)];
    }

    public function test_reintegra_solo_la_falta_y_exporta_solo_la_diferencia_sin_modificar_la_boleta(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId, $service] = $this->escenario();
        $linea = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id])[0];
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Era su descanso', $usuarioId);
        $detalle = $item->detalles->first();
        $this->assertSame('46.67', $detalle->diferencia_neta);
        $this->assertSame('1435.28', $detalle->neto_recalculado);
        $this->assertEquals(1788.27, $detalle->calculo_snapshot['total_ingresos']);
        $this->assertEquals(352.99, $detalle->calculo_snapshot['total_egresos']);
        $this->assertSame('1388.61', $boleta->fresh()->neto_a_pagar);
        $this->assertSame('46.67', $boleta->conceptos()->first()->monto);
        $service->aprobar($empresa, $item, $usuarioId);
        $this->assertSame('46.67', $service->boletasDePago($empresa, $item, '4')->first()->neto_a_pagar);
        $cuenta = new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']);
        $txt = $service->exportarBcp($empresa, $item, $cuenta, '2026-09-05', '4');
        $lineas = explode("\r\n", trim($txt));
        $this->assertCount(2, $lineas);
        $this->assertSame('00000000000046.67', substr($lineas[0], 41, 17));
        $this->assertSame('00000000000046.67', substr($lineas[1], 177, 17));
        $service->marcarPagada($empresa, $item, $usuarioId, 'OP-TEST');
        $restantes = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]);
        $this->assertNotContains('DESCUENTO_FALTA', array_column($restantes, 'codigo'));
        $this->expectException(ValidationException::class);
        $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Duplicado', $usuarioId);
    }

    public function test_reintegro_parcial_reserva_el_descuento_y_libera_el_saldo_tras_el_pago(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId, $service] = $this->escenario();
        $linea = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id])[0];
        $linea['monto'] = 20;
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Subsanación parcial', $usuarioId);
        try {
            $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Duplicado pendiente', $usuarioId);
            $this->fail('Debió rechazar una segunda complementaria pendiente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('descuentos', $e->errors());
        }
        $service->aprobar($empresa, $item, $usuarioId);
        $service->marcarPagada($empresa, $item, $usuarioId, 'OP-TEST');
        $restante = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id])[0];
        $this->assertEquals(26.67, $restante['monto']);
        $segundo = $service->reintegrarDescuentos($empresa, $ciclo, [$restante], 'Saldo', $usuarioId);
        $this->assertSame('26.67', $segundo->detalles->first()->diferencia_neta);
        $this->assertSame('1435.28', $segundo->detalles->first()->neto_recalculado);
    }

    public function test_rechaza_exceso_y_boletas_de_otro_ciclo(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId, $service] = $this->escenario();
        $linea = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id])[0];
        $linea['monto'] = 100;
        try {
            $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Exceso', $usuarioId);
            $this->fail('Debió rechazar un importe mayor al descuento.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('descuentos', $e->errors());
        }
        $this->assertDatabaseCount('planillas_complementarias', 0);
        $this->expectException(ValidationException::class);
        $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id + 99999]);
    }

    public function test_endpoint_valida_y_genera_reintegro(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId] = $this->escenario();
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser(User::findOrFail($usuarioId));
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
        $url = "/api/ciclos-remunerativos/{$ciclo->id}/complementarias";
        $linea = $this->getJson($url.'/descuentos?boleta_ids[]='.$boleta->id)->assertOk()->json('data.0');
        $this->postJson($url.'/reintegrar-descuentos', ['motivo' => 'Descanso', 'descuentos' => [[...$linea, 'monto' => 10.001]]])
            ->assertUnprocessable()->assertJsonValidationErrors('descuentos.0.monto');
        $this->postJson($url.'/reintegrar-descuentos', ['motivo' => 'Descanso', 'descuentos' => [$linea]])
            ->assertCreated()->assertJsonPath('data.total_a_pagar', '46.67')
            ->assertJsonPath('data.detalles.0.reintegros_descuentos.0.codigo', 'DESCUENTO_FALTA');
    }

    public function test_oculta_reservados_desde_calculada_y_los_restaura_al_eliminar(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId, $service] = $this->escenario();
        $linea = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id])[0];
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$linea], 'Descanso', $usuarioId);
        $restantes = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]);
        $this->assertCount(2, $restantes);
        $this->assertNotContains('DESCUENTO_FALTA', array_column($restantes, 'codigo'));
        $this->assertFalse($restantes[0]['reintegrable']);
        $this->assertSame($item->id, $restantes[0]['complementaria_pendiente_id']);
        $service->eliminar($empresa, $item);
        $restaurados = $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]);
        $this->assertCount(3, $restaurados);
        $this->assertTrue($restaurados[0]['reintegrable']);
        $this->assertEquals(46.67, $restaurados[0]['monto']);
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$restaurados[0]], 'Descanso', $usuarioId);
        $service->aprobar($empresa, $item, $usuarioId);
        $this->assertCount(2, $service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]));
    }

    public function test_genera_un_lote_y_un_txt_para_41_colaboradores(): void
    {
        [$empresa, $ciclo, $boleta, $usuarioId, $service] = $this->escenario();
        $ids = [$boleta->id];
        for ($i = 1; $i <= 40; $i++) {
            $colaborador = $this->crearColaborador($empresa, [
                'tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios',
                'numero_documento' => (string) (80000000 + $i),
                'banco_id' => $boleta->colaborador->banco_id, 'numero_cuenta' => '19123456789012',
                'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN',
            ]);
            $copia = $boleta->replicate();
            $copia->colaborador_id = $colaborador->id;
            $copia->save();
            foreach ($boleta->conceptos as $concepto) {
                $linea = $concepto->replicate();
                $linea->boleta_id = $copia->id;
                $linea->save();
            }
            $ids[] = $copia->id;
        }
        $descuentos = $service->descuentosReintegrables($empresa, $ciclo, $ids);
        $this->assertCount(123, $descuentos);
        $faltas = array_values(array_filter($descuentos, fn ($d) => $d['codigo'] === 'DESCUENTO_FALTA'));
        $item = $service->reintegrarDescuentos($empresa, $ciclo, $faltas, 'Subsanación del lote', $usuarioId);
        $this->assertCount(41, $item->detalles);
        $this->assertSame('1913.47', $item->detalles->reduce(fn ($total, $d) => bcadd($total, $d->diferencia_neta, 2), '0.00'));
        $restantes = $service->descuentosReintegrables($empresa, $ciclo, $ids);
        $this->assertCount(82, $restantes);
        $this->assertNotContains('DESCUENTO_FALTA', array_column($restantes, 'codigo'));
        $service->aprobar($empresa, $item, $usuarioId);
        $cuenta = new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']);
        $lineas = explode("\r\n", trim($service->exportarBcp($empresa, $item, $cuenta, '2026-09-05', '4')));
        $this->assertCount(42, $lineas);
        $this->assertSame('000041', substr($lineas[0], 1, 6));
        $this->assertSame('00000000001913.47', substr($lineas[0], 41, 17));
    }
}
