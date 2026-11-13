<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Infrastructure\EnvioBoletas\BoletaMail;
use App\Modules\Nominas\Infrastructure\EnvioBoletas\BoletaPdf;
use App\Modules\Nominas\Jobs\EnviarBoletaPorCorreoJob;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaEnvio;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Support\NumeroALetras;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class EnvioBoletasCorreoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private const URL = '/api/ciclos-remunerativos/%d/boletas/envio-correo';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function usuario(Empresa $empresa): User
    {
        $user = User::factory()->create(['empresa_id' => $empresa->id]);
        $user->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => Role::administrador()->id]]);

        return $user->fresh();
    }

    private function autenticar(Empresa $empresa): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($this->usuario($empresa))]);
    }

    private function ciclo(Empresa $empresa): CicloRemunerativo
    {
        return CicloRemunerativo::create(['empresa_id' => $empresa->id, 'nombre' => 'Planilla Octubre 2026', 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
            'fecha_corte_asistencia' => '2026-10-31', 'fecha_pago' => '2026-10-31', 'estado' => 'pagado']);
    }

    private function boleta(Empresa $empresa, CicloRemunerativo $ciclo, array $colaborador, array $boleta = []): Boleta
    {
        $c = $this->crearColaborador($empresa, ['fecha_ingreso' => '2025-01-02', ...$colaborador]);
        $b = Boleta::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $c->id, 'version' => 1,
            'regimen_laboral_snapshot' => $c->regimen_laboral, 'sueldo_basico_snapshot' => 1230, 'dias_pagados' => 30,
            'total_ingresos' => 1230, 'total_egresos' => 159.90, 'total_aportaciones' => 110.70, 'neto_a_pagar' => 1070.10, 'estado' => 'pagada',
            'es_version_vigente' => true, 'snapshot_parametros_version' => 't', 'snapshot_reglas_version' => 't', 'calculado_at' => now(),
            'pagado_at' => '2026-10-31 10:00:00', ...$boleta]);
        foreach ([['SUELDO_BASICO', 'ingreso', 1230], ['ONP', 'egreso', 159.90], ['ESSALUD', 'aportacion', 110.70]] as [$codigo, $tipo, $monto]) {
            $b->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id, 'tipo' => $tipo,
                'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso', 'monto' => $monto, 'formula_texto' => 'prueba']);
        }

        return $b;
    }

    /** @return array{0: Empresa, 1: CicloRemunerativo, 2: array<string, Boleta>} */
    private function escenario(): array
    {
        $empresa = Empresa::factory()->create(['regimen_laboral' => 'General']);
        $ciclo = $this->ciclo($empresa);
        $boletas = [
            'con_correo' => $this->boleta($empresa, $ciclo, ['email' => 'ana.ficticia@example.com', 'regimen_laboral' => 'General', 'sistema_previsional' => 'onp']),
            'sin_correo' => $this->boleta($empresa, $ciclo, ['email' => null, 'regimen_laboral' => 'General']),
            'correo_invalido' => $this->boleta($empresa, $ciclo, ['email' => 'no-es-un-correo', 'regimen_laboral' => 'General']),
            'locador' => $this->boleta($empresa, $ciclo, ['email' => 'locador.ficticio@example.com', 'regimen_laboral' => 'Locacion de Servicios']),
            'no_pagada' => $this->boleta($empresa, $ciclo, ['email' => 'calculada.ficticia@example.com', 'regimen_laboral' => 'General'], ['estado' => 'aprobada', 'pagado_at' => null]),
        ];

        return [$empresa, $ciclo, $boletas];
    }

    public function test_previsualizar_separa_enviables_y_omitidas_con_su_motivo(): void
    {
        [$empresa, $ciclo, $b] = $this->escenario();
        $this->autenticar($empresa);

        $respuesta = $this->postJson(sprintf(self::URL, $ciclo->id).'/previsualizar', ['boleta_ids' => collect($b)->pluck('id')->all()])
            ->assertOk()->json('data');

        $this->assertSame([$b['con_correo']->id], array_column($respuesta['enviables'], 'boleta_id'));
        $this->assertSame('ana.ficticia@example.com', $respuesta['enviables'][0]['email']);
        $motivos = collect($respuesta['omitidas'])->pluck('motivo', 'boleta_id');
        $this->assertStringContainsString('No tiene correo', $motivos[$b['sin_correo']->id]);
        $this->assertStringContainsString('no es válido', $motivos[$b['correo_invalido']->id]);
        $this->assertStringContainsString('Locación de servicios', $motivos[$b['locador']->id]);
        $this->assertStringContainsString('aún no está pagada', $motivos[$b['no_pagada']->id]);
        $this->assertSame(0, BoletaEnvio::count(), 'Previsualizar no registra envíos.');
    }

    public function test_enviar_manda_la_boleta_pagada_y_registra_el_envio(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        $user = $this->usuario($empresa);

        $resultado = $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)])
            ->postJson(sprintf(self::URL, $ciclo->id), ['boleta_ids' => collect($b)->pluck('id')->all()])
            ->assertStatus(202)->json('data');

        $this->assertSame(1, $resultado['encolados']);
        $this->assertCount(4, $resultado['omitidas']);
        Mail::assertSentCount(1);
        Mail::assertSent(BoletaMail::class, fn (BoletaMail $m) => $m->hasTo('ana.ficticia@example.com')
            && count($m->attachments()) === 1 && $m->periodo === 'Planilla Octubre 2026');

        $envio = BoletaEnvio::sole();
        $this->assertSame(['enviado', BoletaEnvio::TIPO_BOLETA, $b['con_correo']->id, $user->id],
            [$envio->estado, $envio->tipo, $envio->boleta_id, $envio->enviado_por]);
        $this->assertNotNull($envio->enviado_at);
    }

    public function test_no_reenvia_a_quien_ya_la_recibio_salvo_que_se_pida(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        $this->autenticar($empresa);
        $url = sprintf(self::URL, $ciclo->id);
        $this->postJson($url, ['boleta_ids' => [$b['con_correo']->id]])->assertStatus(202);
        Mail::assertSentCount(1);

        // Seleccionar de nuevo (ej. a todos para enviar a los que faltan) no la duplica.
        $previa = $this->postJson("{$url}/previsualizar", ['boleta_ids' => [$b['con_correo']->id]])->json('data');
        $this->assertSame([], $previa['enviables']);
        $this->assertTrue($previa['omitidas'][0]['ya_recibida']);
        $this->assertSame(0, $this->postJson($url, ['boleta_ids' => [$b['con_correo']->id]])->json('data.encolados'));
        Mail::assertSentCount(1);

        // Reenvío explícito (ej. no encuentra el correo).
        $this->assertSame(1, $this->postJson($url, ['boleta_ids' => [$b['con_correo']->id], 'reenviar' => true])->json('data.encolados'));
        Mail::assertSentCount(2);
    }

    public function test_la_planilla_muestra_el_estado_del_envio(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        $this->autenticar($empresa);
        $this->postJson(sprintf(self::URL, $ciclo->id), ['boleta_ids' => [$b['con_correo']->id]])->assertStatus(202);

        $filas = collect($this->getJson("/api/ciclos-remunerativos/{$ciclo->id}/boletas?per_page=100")->assertOk()->json('data'));
        $envio = $filas->firstWhere('colaborador.id', $b['con_correo']->colaborador_id)['envio_correo'];
        $this->assertSame(['enviado', false], [$envio['estado'], $envio['desactualizada']]);
        $this->assertNull($filas->firstWhere('colaborador.id', $b['sin_correo']->colaborador_id)['envio_correo']);
    }

    public function test_un_envio_fallido_se_puede_volver_a_enviar(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        BoletaEnvio::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $b['con_correo']->colaborador_id,
            'boleta_id' => $b['con_correo']->id, 'boleta_version' => 1, 'tipo' => BoletaEnvio::TIPO_BOLETA,
            'email' => 'ana.ficticia@example.com', 'estado' => 'fallido', 'error' => 'SMTP caído']);
        $this->autenticar($empresa);

        $this->assertSame(1, $this->postJson(sprintf(self::URL, $ciclo->id), ['boleta_ids' => [$b['con_correo']->id]])->json('data.encolados'));
        Mail::assertSentCount(1);
    }

    public function test_un_envio_de_una_boleta_que_dejo_de_estar_pagada_queda_fallido(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        $envio = BoletaEnvio::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $b['con_correo']->colaborador_id,
            'boleta_id' => $b['con_correo']->id, 'boleta_version' => 1, 'tipo' => BoletaEnvio::TIPO_BOLETA,
            'email' => 'ana.ficticia@example.com', 'estado' => 'pendiente']);
        $b['con_correo']->update(['es_version_vigente' => false]);

        dispatch_sync(new EnviarBoletaPorCorreoJob($envio->id));

        Mail::assertNothingSent();
        $this->assertSame('fallido', $envio->fresh()->estado);
        $this->assertStringContainsString('cambió antes del envío', $envio->fresh()->error);
    }

    public function test_usuario_de_otra_empresa_no_puede_previsualizar_ni_enviar(): void
    {
        Mail::fake();
        [, $ciclo, $b] = $this->escenario();
        // Un administrador es global por diseño (User::tieneAccesoA): el aislamiento
        // se prueba con un rol de nóminas que solo pertenece a otra empresa.
        $otra = Empresa::factory()->create();
        $rol = Role::create(['clave' => 'nomina_envio_boleta', 'nombre' => 'Nómina envío de boletas']);
        $rol->permissions()->sync(Permission::whereIn('clave', ['nominas.ver', 'nominas.aprobar'])->pluck('id'));
        $ajeno = User::factory()->create(['empresa_id' => $otra->id]);
        $ajeno->empresas()->syncWithoutDetaching([$otra->id => ['role_id' => $rol->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($ajeno->fresh())]);

        $this->postJson(sprintf(self::URL, $ciclo->id).'/previsualizar', ['boleta_ids' => [$b['con_correo']->id]])->assertForbidden();
        $this->postJson(sprintf(self::URL, $ciclo->id), ['boleta_ids' => [$b['con_correo']->id]])->assertForbidden();
        Mail::assertNothingSent();
        $this->assertSame(0, BoletaEnvio::count());
    }

    public function test_boleta_de_otro_ciclo_no_se_cuela_en_el_envio(): void
    {
        Mail::fake();
        [$empresa, $ciclo, $b] = $this->escenario();
        $otraEmpresa = Empresa::factory()->create(['regimen_laboral' => 'General']);
        $ajena = $this->boleta($otraEmpresa, $this->ciclo($otraEmpresa), ['email' => 'ajeno.ficticio@example.com', 'regimen_laboral' => 'General']);
        $this->autenticar($empresa);

        $resultado = $this->postJson(sprintf(self::URL, $ciclo->id), ['boleta_ids' => [$ajena->id, $b['con_correo']->id]])
            ->assertStatus(202)->json('data');

        $this->assertSame(1, $resultado['encolados']);
        Mail::assertNotSent(BoletaMail::class, fn (BoletaMail $m) => $m->hasTo('ajeno.ficticio@example.com'));
    }

    public function test_el_pdf_es_la_boleta_oficial_con_el_neto(): void
    {
        [$empresa, , $b] = $this->escenario();
        $boleta = app(BoletaService::class)->ver($empresa, $b['con_correo']);
        $pdf = app(BoletaPdf::class);

        $datos = $pdf->datos($boleta);
        $this->assertEqualsWithDelta(1070.10, $datos['neto'], 0.001);
        $this->assertSame('MIL SETENTA Y 10/100 SOLES', $datos['netoEnLetras']);
        $html = view('nominas.boleta-pdf', $datos)->render();
        $this->assertStringContainsString('Boleta de pago', $html);
        $this->assertStringContainsString('31/10/2026', $html);
        $this->assertStringNotContainsStringIgnoringCase('borrador', $html);
        $this->assertStringStartsWith('%PDF', $pdf->generar($boleta));
    }

    public function test_numero_a_letras_coincide_con_el_frontend(): void
    {
        $this->assertSame('CERO Y 00/100 SOLES', NumeroALetras::convertir(0));
        $this->assertSame('CIEN Y 00/100 SOLES', NumeroALetras::convertir(100));
        $this->assertSame('VEINTIUN MIL QUINIENTOS Y 05/100 SOLES', NumeroALetras::convertir(21500.05));
        $this->assertSame('UN MILLON DOSCIENTOS MIL Y 00/100 DOLARES', NumeroALetras::convertir(1200000, 'USD'));
        $this->assertSame('TREINTA Y UNO Y 99/100 SOLES', NumeroALetras::convertir(31.99));
    }
}
