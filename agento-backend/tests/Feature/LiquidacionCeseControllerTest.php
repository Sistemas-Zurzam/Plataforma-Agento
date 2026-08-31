<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cubre el ciclo de vida completo vía HTTP (calculada → aprobada → pagada,
 * y calculada → aprobada → anulada con reversión del cese) y el aislamiento
 * multiempresa de LiquidacionCeseController — DatabaseSeeder ya deja un
 * usuario administrador ("test.user") con acceso a las 5 empresas de
 * prueba, así que "cambiar de empresa activa" en estos tests es solo
 * actualizar users.empresa_id (la misma columna que lee el controller vía
 * $request->user('api')->empresa — el claim del JWT no es la fuente de
 * autorización, ver ColaboradorController/LiquidacionCeseController).
 */
class LiquidacionCeseControllerTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function autenticarEn(Empresa $empresa): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $empresa->id]);
        $token = JWTAuth::fromUser($usuario);
        // JWTGuard::user() cachea el usuario resuelto en la instancia del
        // guard, y Laravel reutiliza esa misma instancia entre llamadas HTTP
        // simuladas dentro de un mismo test — sin este forgetGuards(), un
        // segundo autenticarEn() con otra empresa seguiría resolviendo el
        // usuario (y su empresa_id) de la primera autenticación. No ocurre
        // en producción: cada request real es un proceso nuevo.
        Auth::forgetGuards();

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    private function cesarColaborador(array $headers, $colaborador): int
    {
        $respuesta = $this->withHeaders($headers)->patchJson("/api/colaboradores/{$colaborador->id}/cesar", [
            'fecha_cese' => now()->toDateString(),
            'motivo_cese' => 'Renuncia voluntaria',
            'incluir_remuneracion' => true,
            'incluir_cts' => false,
            'incluir_gratificacion' => false,
            'incluir_vacaciones' => false,
        ]);
        $respuesta->assertOk();

        return $colaborador->liquidacionesCese()->where('es_version_vigente', true)->firstOrFail()->id;
    }

    public function test_flujo_completo_calcular_aprobar_y_pagar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $liquidacionId = $this->cesarColaborador($headers, $colaborador);

        $this->withHeaders($headers)->getJson('/api/liquidaciones-cese')
            ->assertOk()
            ->assertJsonFragment(['id' => $liquidacionId, 'estado' => 'calculada']);

        // Pagar antes de aprobar debe rechazarse (máquina de estados).
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/pagar", ['referencia_pago' => 'OP-1'])
            ->assertStatus(422);

        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")
            ->assertOk()->assertJsonPath('data.estado', 'aprobada');

        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/pagar", ['referencia_pago' => 'OP-1'])
            ->assertOk()->assertJsonPath('data.estado', 'pagada');

        // Pagada: ya no se puede revertir.
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/anular-revertir", ['motivo' => 'Error'])
            ->assertStatus(422);
    }

    public function test_anular_y_revertir_reactiva_al_colaborador(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $liquidacionId = $this->cesarColaborador($headers, $colaborador);
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")->assertOk();

        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/anular-revertir", ['motivo' => 'Colaborador se retractó'])
            ->assertOk();

        $this->assertDatabaseHas('liquidaciones_cese', ['id' => $liquidacionId, 'estado' => 'anulada', 'es_version_vigente' => false]);
        $colaborador->refresh();
        $this->assertTrue($colaborador->activo);
        $this->assertNull($colaborador->fecha_cese);

        // El colaborador reactivado puede volver a cesarse — nueva versión.
        $segundaLiquidacionId = $this->cesarColaborador($headers, $colaborador);
        $this->assertNotSame($liquidacionId, $segundaLiquidacionId);
        $this->assertDatabaseHas('liquidaciones_cese', ['id' => $segundaLiquidacionId, 'version' => 2, 'es_version_vigente' => true]);
    }

    public function test_una_empresa_no_puede_operar_liquidaciones_de_otra(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::where('nombre_comercial', 'Zazu')->firstOrFail();
        $empresaB = Empresa::where('nombre_comercial', 'Agento')->firstOrFail();
        [, $headersA] = $this->autenticarEn($empresaA);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresaA);
        $colaborador = $this->crearColaborador($empresaA);

        $liquidacionId = $this->cesarColaborador($headersA, $colaborador);

        // Mismo usuario admin, pero con la empresa activa cambiada a B —
        // ya no debe poder ver ni operar la liquidación creada bajo A.
        [, $headersB] = $this->autenticarEn($empresaB);

        $this->withHeaders($headersB)->getJson("/api/liquidaciones-cese/{$liquidacionId}")->assertStatus(403);
        $this->withHeaders($headersB)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")->assertStatus(403);
        $this->withHeaders($headersB)->getJson('/api/liquidaciones-cese')->assertOk()->assertJsonMissing(['id' => $liquidacionId]);
    }
}
