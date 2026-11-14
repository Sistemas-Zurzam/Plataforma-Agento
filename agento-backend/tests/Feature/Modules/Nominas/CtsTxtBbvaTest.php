<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/** TXT de depósito de CTS para BBVA desde la CTS calculada. Datos ficticios. */
class CtsTxtBbvaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private BeneficioSocial $cts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'Empresa Prueba']);
        $this->cts = BeneficioSocial::create(['empresa_id' => $this->empresa->id, 'tipo' => 'cts_noviembre', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
            'total_colaboradores' => 1, 'total_bruto' => 0, 'total_neto' => 0, 'estado' => 'calculado', 'calculado_at' => now()]);
    }

    private function cuentaCargo(string $uso = 'haberes'): void
    {
        $bbva = Banco::where('codigo', 'bbva')->firstOrFail();
        EmpresaCuentaBancaria::create(['empresa_id' => $this->empresa->id, 'banco_id' => $bbva->id, 'tipo_cuenta' => 'corriente', 'moneda' => 'PEN',
            'numero_cuenta' => '00110000000000000009', 'uso' => $uso, 'es_predeterminada' => true, 'activo' => true]);
    }

    private function detalle(array $colaborador, float $neta, string $estado = 'calculado'): BeneficioSocialDetalle
    {
        $c = $this->crearColaborador($this->empresa, ['fecha_ingreso' => '2026-01-01', 'cts_cuenta' => '00110000000000000001', ...$colaborador]);

        return BeneficioSocialDetalle::create(['beneficio_social_id' => $this->cts->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 1000, 'meses' => 6,
            'bruta' => $neta, 'bonificacion_extraordinaria' => 0, 'neta' => $neta, 'detalle_calculo' => ['estado' => $estado]]);
    }

    private function autenticar(): void
    {
        $user = User::factory()->create(['empresa_id' => $this->empresa->id]);
        $user->empresas()->syncWithoutDetaching([$this->empresa->id => ['role_id' => Role::administrador()->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user->fresh())]);
    }

    public function test_incluye_solo_a_quien_corresponde_y_genera_el_txt(): void
    {
        $this->cuentaCargo();
        $ok = $this->detalle(['nombres' => 'Ana', 'apellidos' => 'Prueba Uno', 'numero_documento' => '11111111'], 331.04);
        $parcial = $this->detalle(['nombres' => 'Beto', 'apellidos' => 'Prueba Dos', 'numero_documento' => '22222222'], 200);
        BeneficioSocialDeposito::create(['beneficio_social_detalle_id' => $parcial->id, 'fecha_deposito' => '2026-11-10', 'importe' => 50, 'entidad' => 'BBVA', 'referencia' => 'X-1', 'idempotencia' => str_repeat('c', 64)]);
        $observado = $this->detalle(['numero_documento' => '33333333'], 100, 'observado');
        $sinCuenta = $this->detalle(['numero_documento' => '44444444', 'cts_cuenta' => null], 100);
        $otroBanco = $this->detalle(['numero_documento' => '55555555', 'cts_cuenta' => '19100000000000000001'], 100);
        $ids = [$ok->id, $parcial->id, $observado->id, $sinCuenta->id, $otroBanco->id];
        $this->autenticar();

        $v = $this->postJson("/api/beneficios-sociales/{$this->cts->id}/cts/txt-bbva/validacion", ['detalle_ids' => $ids])->assertOk()->json('data');

        $this->assertNull($v['error']);
        $this->assertSame([$ok->id, $parcial->id], array_column($v['incluidos'], 'detalle_id'));
        $this->assertSame(481.04, $v['total']); // 331.04 + saldo 150
        $this->assertSame('****0009', $v['cuenta_cargo']['numero']);
        $motivos = collect($v['omitidos'])->pluck('motivo', 'detalle_id');
        $this->assertStringContainsString('observado', $motivos[$observado->id]);
        $this->assertStringContainsString('cuenta CTS', $motivos[$sinCuenta->id]);
        $this->assertStringContainsString('no es de BBVA', $motivos[$otroBanco->id]);

        $respuesta = $this->post("/api/beneficios-sociales/{$this->cts->id}/cts/txt-bbva", ['detalle_ids' => $ids])->assertOk();
        $lineas = explode("\n", $respuesta->getContent());
        $this->assertStringContainsString('BBVA_CTS_EMPRESA_PRUEBA_CTS_NOVIEMBRE_2026.txt', $respuesta->headers->get('Content-Disposition'));
        $this->assertCount(3, $lineas);
        $this->assertSame('000000000048104', substr($lineas[0], 26, 15));
        $this->assertSame('000002', substr($lineas[0], 76, 6));
        $this->assertSame('000000000015000', substr($lineas[2], 77, 15)); // solo el saldo pendiente
        $this->assertSame(0, BeneficioSocialDeposito::where('beneficio_social_detalle_id', $ok->id)->count(), 'Generar el TXT no registra el depósito.');
    }

    public function test_prefiere_la_cuenta_de_cargo_de_cts_y_exige_alguna_cuenta_bbva(): void
    {
        $d = $this->detalle(['numero_documento' => '11111111'], 100);
        $this->autenticar();
        $url = "/api/beneficios-sociales/{$this->cts->id}/cts/txt-bbva";

        $this->assertStringContainsString('no tiene una cuenta BBVA', $this->postJson("{$url}/validacion", ['detalle_ids' => [$d->id]])->json('data.error'));
        $this->postJson($url, ['detalle_ids' => [$d->id]])->assertStatus(422);

        $this->cuentaCargo('cts');
        $this->assertSame('cts', $this->postJson("{$url}/validacion", ['detalle_ids' => [$d->id]])->json('data.cuenta_cargo.uso'));
    }

    public function test_otra_empresa_no_genera_el_txt(): void
    {
        $this->cuentaCargo();
        $d = $this->detalle(['numero_documento' => '11111111'], 100);
        $otra = Empresa::factory()->create();
        $user = User::factory()->create(['empresa_id' => $otra->id]);
        $user->empresas()->syncWithoutDetaching([$otra->id => ['role_id' => Role::administrador()->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user->fresh())]);

        $this->post("/api/beneficios-sociales/{$this->cts->id}/cts/txt-bbva", ['detalle_ids' => [$d->id]])->assertNotFound();
    }
}
