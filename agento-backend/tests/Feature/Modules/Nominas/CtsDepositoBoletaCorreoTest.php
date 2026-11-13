<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsMail;
use App\Modules\Nominas\Infrastructure\Cts\BoletaCtsPdf;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\BeneficioSocialEnvio;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/** Depósito masivo de CTS, boleta de CTS en PDF y su envío por correo. Datos ficticios. */
class CtsDepositoBoletaCorreoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private BeneficioSocial $cts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::factory()->create();
        $this->cts = BeneficioSocial::create(['empresa_id' => $this->empresa->id, 'tipo' => 'cts_noviembre', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
            'total_colaboradores' => 2, 'total_bruto' => 0, 'total_neto' => 0, 'estado' => 'calculado', 'calculado_at' => now()]);
    }

    private function detalle(string $email, float $neta, string $estado = 'calculado'): BeneficioSocialDetalle
    {
        $c = $this->crearColaborador($this->empresa, ['email' => $email, 'fecha_ingreso' => '2026-01-01', 'cts_cuenta' => '0011-0000-0000000000']);

        return BeneficioSocialDetalle::create(['beneficio_social_id' => $this->cts->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 1200, 'meses' => 6,
            'bruta' => $neta, 'bonificacion_extraordinaria' => 0, 'neta' => $neta, 'detalle_calculo' => [
                'estado' => $estado, 'conceptos' => ['SUELDO_BASICO' => 1200, 'COMISION' => 100.0], 'gratificacion_legal_percibida' => 600,
                'gratificacion_sexto' => 100.0, 'meses' => 6, 'dias' => 0, 'dias_computables' => 180, 'tasa' => 0.5, 'alertas' => [],
            ]]);
    }

    private function autenticar(?Empresa $empresa = null): void
    {
        $empresa ??= $this->empresa;
        $user = User::factory()->create(['empresa_id' => $empresa->id]);
        $user->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => Role::administrador()->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user->fresh())]);
    }

    public function test_registra_el_deposito_de_los_seleccionados_y_omite_observados(): void
    {
        $ok = $this->detalle('ana.prueba@example.com', 350);
        $observado = $this->detalle('beto.prueba@example.com', 200, 'observado');
        $this->autenticar();

        $resultado = $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/depositos", [
            'detalle_ids' => [$ok->id, $observado->id], 'fecha_deposito' => '2026-11-13', 'entidad' => 'BBVA', 'operacion' => 'OP123',
        ])->assertOk()->json('data');

        $this->assertSame(1, $resultado['registrados']);
        $this->assertStringContainsString('observado', $resultado['omitidos'][0]['motivo']);
        $this->assertEquals(350, (float) BeneficioSocialDeposito::where('beneficio_social_detalle_id', $ok->id)->sum('importe'));
        $this->assertSame('conciliado', $ok->fresh()->estado_deposito);

        // Repetir no duplica: ya está completo.
        $repetido = $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/depositos", [
            'detalle_ids' => [$ok->id], 'fecha_deposito' => '2026-11-13', 'entidad' => 'BBVA', 'operacion' => 'OP123',
        ])->json('data');
        $this->assertSame(0, $repetido['registrados']);
        $this->assertSame(1, BeneficioSocialDeposito::count());
    }

    public function test_la_boleta_de_cts_muestra_base_calculo_y_deposito(): void
    {
        $d = $this->detalle('ana.prueba@example.com', 650);
        BeneficioSocialDeposito::create(['beneficio_social_detalle_id' => $d->id, 'fecha_deposito' => '2026-11-13', 'importe' => 650, 'entidad' => 'BBVA',
            'referencia' => 'OP9-1', 'idempotencia' => str_repeat('b', 64)]);
        $pdf = app(BoletaCtsPdf::class);

        $datos = $pdf->datos($this->cts, $d->fresh(['colaborador', 'depositos']));
        $html = view('nominas.boleta-cts', ['paginas' => [$datos], 'logo' => null])->render();

        $this->assertSame('Mayo 2026 a Octubre 2026', $datos['periodo']);
        $this->assertSame(1400.0, $datos['base_total']); // 1,200 + comisión 100 + 1/6 gratificación 100
        $this->assertStringContainsString('Liquidación de depósito de CTS', $html);
        $this->assertStringContainsString('13/11/2026', $html);
        $this->assertStringContainsString('0011-0000-0000000000', $html);
        $this->assertStringContainsString('50% (régimen pequeña empresa)', $html);
        $this->assertStringStartsWith('%PDF', $pdf->generar($this->cts, collect([$d->fresh(['colaborador', 'depositos'])])));

        $this->autenticar();
        $this->get("/api/beneficios-sociales/detalles/{$d->id}/boleta-cts")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post("/api/beneficios-sociales/{$this->cts->id}/cts/boletas", ['detalle_ids' => [$d->id]])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_el_correo_solo_sale_despues_del_deposito_y_una_vez(): void
    {
        Mail::fake();
        $depositado = $this->detalle('ana.prueba@example.com', 300);
        $sinDeposito = $this->detalle('beto.prueba@example.com', 300);
        $this->autenticar();
        $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/depositos", [
            'detalle_ids' => [$depositado->id], 'fecha_deposito' => '2026-11-13', 'entidad' => 'BBVA', 'operacion' => 'OP5',
        ])->assertOk();
        $url = "/api/beneficios-sociales/{$this->cts->id}/cts/envio-correo";

        $previa = $this->postJson("{$url}/previsualizar", ['detalle_ids' => [$depositado->id, $sinDeposito->id]])->json('data');
        $this->assertSame([$depositado->id], array_column($previa['enviables'], 'detalle_id'));
        $this->assertStringContainsString('depósito', $previa['omitidas'][0]['motivo']);

        $this->assertSame(1, $this->postJson($url, ['detalle_ids' => [$depositado->id, $sinDeposito->id]])->assertStatus(202)->json('data.encolados'));
        Mail::assertSent(BoletaCtsMail::class, fn (BoletaCtsMail $m) => $m->hasTo('ana.prueba@example.com') && count($m->attachments()) === 1);
        $this->assertSame('enviado', BeneficioSocialEnvio::sole()->estado);

        // Una vez: no se repite salvo reenvío explícito.
        $this->assertSame(0, $this->postJson($url, ['detalle_ids' => [$depositado->id]])->json('data.encolados'));
        $this->assertSame(1, $this->postJson($url, ['detalle_ids' => [$depositado->id], 'reenviar' => true])->json('data.encolados'));
        Mail::assertSentCount(2);

        $fila = collect($this->getJson('/api/beneficios-sociales/resumen?tipo=cts_noviembre&anio=2026')->assertOk()->json('colaboradores'))->firstWhere('detalle_id', $depositado->id);
        $this->assertSame('enviado', $fila['envio_correo']['estado']);
    }

    public function test_otra_empresa_no_accede_a_la_cts(): void
    {
        Mail::fake();
        $d = $this->detalle('ana.prueba@example.com', 300);
        $this->autenticar(Empresa::factory()->create());

        $this->get("/api/beneficios-sociales/detalles/{$d->id}/boleta-cts")->assertNotFound();
        $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/depositos", ['detalle_ids' => [$d->id], 'fecha_deposito' => '2026-11-13', 'entidad' => 'BBVA', 'operacion' => 'X'])->assertNotFound();
        $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/envio-correo", ['detalle_ids' => [$d->id]])->assertNotFound();
        $this->assertSame(0, BeneficioSocialDeposito::count());
        Mail::assertNothingSent();
    }
}
