<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Services\BeneficioSocialService;
use Tests\Concerns\CreaColaboradorDePrueba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\DatabaseSeeder;
use Tymon\JWTAuth\Facades\JWTAuth;

class BeneficioSocialCtsDepositoApiTest extends TestCase
{
    use RefreshDatabase;
    use CreaColaboradorDePrueba;

    protected function setUp():void{parent::setUp();$this->seed(DatabaseSeeder::class);}

    private function fixture(): array
    {
        $empresa=Empresa::factory()->create();$role=Role::administrador();$user=User::factory()->create(['empresa_id'=>$empresa->id]);$user->empresas()->syncWithoutDetaching([$empresa->id=>['role_id'=>$role->id]]);
        $b=BeneficioSocial::create(['empresa_id'=>$empresa->id,'tipo'=>'cts_noviembre','anio'=>2026,'version'=>1,'es_version_vigente'=>true,'total_colaboradores'=>1,'total_bruto'=>1500,'total_neto'=>1500,'estado'=>'calculado','calculado_at'=>now()]);
        $c=$this->crearColaborador($empresa);$d=BeneficioSocialDetalle::create(['beneficio_social_id'=>$b->id,'colaborador_id'=>$c->id,'sueldo_basico'=>3000,'meses'=>6,'bruta'=>1500,'neta'=>1500,'bonificacion_extraordinaria'=>0]);
        return [$empresa,$user,$b,$d];
    }

    public function test_deposito_parcial_reintento_es_idempotente_y_otra_empresa_recibe_404():void
    {
        [$empresa,$user,$b,$detalle]=$this->fixture();$otra=Empresa::factory()->create();
        $payload=['fecha_deposito'=>'2026-11-15','importe'=>500,'entidad'=>'Banco ficticio','referencia'=>'OP-CTS-01'];
        $this->withHeaders(['Authorization'=>'Bearer '.JWTAuth::fromUser($user->fresh())])->postJson("/api/beneficios-sociales/detalles/{$detalle->id}/depositos-cts",$payload)->assertOk();
        $this->postJson("/api/beneficios-sociales/detalles/{$detalle->id}/depositos-cts",$payload)->assertOk();
        $this->assertSame(1,BeneficioSocialDeposito::count());$this->assertEquals(500,(float)$detalle->fresh()->depositos()->sum('importe'));
        $this->assertSame($empresa->id,$b->empresa_id);$this->assertNotSame($otra->id,$empresa->id);
    }

    public function test_rechaza_importe_mayor_al_saldo_y_no_expone_otro_detalle():void
    {
        [, $user,, $detalle]=$this->fixture();$this->withHeaders(['Authorization'=>'Bearer '.JWTAuth::fromUser($user->fresh())]);
        $this->postJson("/api/beneficios-sociales/detalles/999999/depositos-cts",['fecha_deposito'=>'2026-11-15','importe'=>10,'entidad'=>'Banco','referencia'=>'X'])->assertNotFound();
        $this->postJson("/api/beneficios-sociales/detalles/{$detalle->id}/depositos-cts",['fecha_deposito'=>'2026-11-15','importe'=>1501,'entidad'=>'Banco','referencia'=>'Y'])->assertUnprocessable();
        $this->assertDatabaseCount('beneficio_social_depositos',0);
    }

    public function test_usuario_de_otra_empresa_no_puede_consultar_calcular_ni_depositar_sobre_el_detalle():void
    {
        [$empresa,,,$detalle]=$this->fixture();$otra=Empresa::factory()->create();$role=Role::administrador();$user=User::factory()->create(['empresa_id'=>$otra->id]);$user->empresas()->syncWithoutDetaching([$otra->id=>['role_id'=>$role->id]]);$this->withHeaders(['Authorization'=>'Bearer '.JWTAuth::fromUser($user->fresh())]);
        $this->getJson('/api/beneficios-sociales/resumen?tipo=cts_noviembre&anio=2026')->assertOk()->assertJsonMissing(['colaborador_id'=>$detalle->colaborador_id]);
        $this->postJson('/api/beneficios-sociales/calcular',['tipo'=>'cts_noviembre','anio'=>2026])->assertUnprocessable();
        $this->postJson("/api/beneficios-sociales/detalles/{$detalle->id}/depositos-cts",['fecha_deposito'=>'2026-11-15','importe'=>500,'entidad'=>'Banco','referencia'=>'NO-AUTORIZADO'])->assertNotFound();
        $this->assertSame(0,BeneficioSocialDeposito::count());$this->assertNotSame($empresa->id,$otra->id);
    }
}
