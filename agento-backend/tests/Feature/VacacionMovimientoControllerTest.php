<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class VacacionMovimientoControllerTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function autenticarEn(Empresa $empresa): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $empresa->id]);
        $token = JWTAuth::fromUser($usuario);

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_crea_lista_y_elimina_un_movimiento(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $creado = $this->withHeaders($headers)->postJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos", [
            'fecha' => now()->toDateString(), 'tipo' => 'ajuste', 'dias' => 5,
            'descripcion' => 'Saldo trasladado del sistema anterior',
        ])->assertCreated()->json('data');

        $this->withHeaders($headers)->getJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos")
            ->assertOk()->assertJsonFragment(['id' => $creado['id'], 'dias' => '5.0000']);

        $this->withHeaders($headers)->deleteJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos/{$creado['id']}")
            ->assertOk();
        $this->assertDatabaseMissing('vacacion_movimientos', ['id' => $creado['id']]);
    }

    public function test_rechaza_dias_en_cero(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $this->withHeaders($headers)->postJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos", [
            'fecha' => now()->toDateString(), 'tipo' => 'ajuste', 'dias' => 0,
            'descripcion' => 'Intento inválido',
        ])->assertStatus(422);
    }

    public function test_no_permite_movimientos_de_un_colaborador_cesado(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        $colaborador = $this->crearColaborador($empresa, ['activo' => false, 'fecha_cese' => now()->toDateString()]);

        $this->withHeaders($headers)->postJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos", [
            'fecha' => now()->toDateString(), 'tipo' => 'ajuste', 'dias' => 5,
            'descripcion' => 'No debería crearse',
        ])->assertStatus(422);
    }

    public function test_una_empresa_no_puede_gestionar_movimientos_de_un_colaborador_de_otra(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::where('nombre_comercial', 'Zazu')->firstOrFail();
        $empresaB = Empresa::where('nombre_comercial', 'Agento')->firstOrFail();
        $colaborador = $this->crearColaborador($empresaA);

        [, $headersB] = $this->autenticarEn($empresaB);

        $this->withHeaders($headersB)->getJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos")->assertStatus(403);
        $this->withHeaders($headersB)->postJson("/api/colaboradores/{$colaborador->id}/vacacion-movimientos", [
            'fecha' => now()->toDateString(), 'tipo' => 'ajuste', 'dias' => 5, 'descripcion' => 'No autorizado',
        ])->assertStatus(403);
    }
}
