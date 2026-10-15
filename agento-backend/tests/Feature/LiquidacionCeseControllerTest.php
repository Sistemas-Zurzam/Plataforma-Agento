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
 * Ciclo de vida vía HTTP con cese y liquidación separados: cesar → aparece
 * en pendientes → liquidar (asistida) → aprobar → pagar; anular no revierte
 * el cese. Incluye el aislamiento multiempresa. DatabaseSeeder deja un
 * usuario administrador ("test.user") con acceso a las empresas de prueba.
 */
class LiquidacionCeseControllerTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function autenticarEn(Empresa $empresa): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $empresa->id]);
        $token = JWTAuth::fromUser($usuario);
        // JWTGuard cachea el usuario entre requests simulados del mismo test.
        Auth::forgetGuards();

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    private function cesar(array $headers, $colaborador): void
    {
        $this->withHeaders($headers)->patchJson("/api/colaboradores/{$colaborador->id}/cesar", [
            'fecha_cese' => now()->toDateString(),
            'motivo_cese' => 'Renuncia voluntaria',
        ])->assertOk()->assertJsonPath('data.activo', false);
    }

    private function liquidar(array $headers, $colaborador): int
    {
        $propuesta = $this->withHeaders($headers)->getJson("/api/liquidaciones-cese/propuesta/{$colaborador->id}")->assertOk()->json('data');
        $lineas = collect($propuesta['conceptos'])->map(fn ($c) => ['codigo' => $c['codigo'], 'monto' => $c['monto']])->all();

        return $this->withHeaders($headers)->postJson('/api/liquidaciones-cese', [
            'colaborador_id' => $colaborador->id, 'modo' => 'asistido', 'conceptos' => $lineas,
        ])->assertCreated()->json('data.id');
    }

    public function test_locacion_de_servicios_puede_cesarse_y_no_queda_pendiente_de_liquidar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        $colaborador = $this->crearColaborador($empresa, ['tipo_contrato' => 'locacion_servicios', 'regimen_laboral' => 'Locacion de Servicios']);

        $this->cesar($headers, $colaborador);

        $this->assertDatabaseMissing('liquidaciones_cese', ['colaborador_id' => $colaborador->id]);
        $this->withHeaders($headers)->getJson('/api/liquidaciones-cese/pendientes')->assertOk()->assertJsonMissing(['id' => $colaborador->id]);
    }

    public function test_flujo_completo_cesar_liquidar_aprobar_y_pagar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $this->cesar($headers, $colaborador);
        $this->assertDatabaseMissing('liquidaciones_cese', ['colaborador_id' => $colaborador->id]);
        $this->withHeaders($headers)->getJson('/api/liquidaciones-cese/pendientes')->assertOk()->assertJsonFragment(['id' => $colaborador->id]);

        $liquidacionId = $this->liquidar($headers, $colaborador);
        $this->withHeaders($headers)->getJson('/api/liquidaciones-cese')->assertOk()
            ->assertJsonFragment(['id' => $liquidacionId, 'estado' => 'calculada']);

        // Pagar antes de aprobar debe rechazarse (máquina de estados).
        $pago = ['referencia_pago' => 'OP-1', 'fecha_pago' => now()->toDateString(), 'medio_pago' => 'transferencia'];
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/pagar", $pago)->assertStatus(422);

        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/pagar", $pago)->assertOk()->assertJsonPath('data.estado', 'pagada');

        // Pagada: ya no se puede anular.
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/anular", ['motivo' => 'Error'])->assertStatus(422);
    }

    public function test_anular_no_revierte_el_cese_y_permite_liquidar_de_nuevo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        [, $headers] = $this->autenticarEn($empresa);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $colaborador = $this->crearColaborador($empresa);

        $this->cesar($headers, $colaborador);
        $liquidacionId = $this->liquidar($headers, $colaborador);
        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")->assertOk();

        // Con liquidación vigente no se puede reactivar al colaborador.
        $this->withHeaders($headers)->patchJson("/api/colaboradores/{$colaborador->id}/reactivar", ['motivo' => 'Se retractó'])->assertStatus(422);

        $this->withHeaders($headers)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/anular", ['motivo' => 'Montos mal calculados'])->assertOk();

        $this->assertDatabaseHas('liquidaciones_cese', ['id' => $liquidacionId, 'estado' => 'anulada', 'es_version_vigente' => false]);
        $colaborador->refresh();
        $this->assertFalse($colaborador->activo);
        $this->assertNotNull($colaborador->fecha_cese);

        $segundaId = $this->liquidar($headers, $colaborador);
        $this->assertDatabaseHas('liquidaciones_cese', ['id' => $segundaId, 'version' => 2, 'es_version_vigente' => true]);
    }

    public function test_una_empresa_no_puede_operar_liquidaciones_de_otra(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::where('nombre_comercial', 'Zazu')->firstOrFail();
        $empresaB = Empresa::where('nombre_comercial', 'Agento')->firstOrFail();
        [, $headersA] = $this->autenticarEn($empresaA);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresaA);
        $colaborador = $this->crearColaborador($empresaA);
        $this->cesar($headersA, $colaborador);
        $liquidacionId = $this->liquidar($headersA, $colaborador);

        // Mismo usuario admin, con la empresa activa cambiada a B.
        [, $headersB] = $this->autenticarEn($empresaB);

        $this->withHeaders($headersB)->getJson("/api/liquidaciones-cese/{$liquidacionId}")->assertStatus(403);
        $this->withHeaders($headersB)->patchJson("/api/liquidaciones-cese/{$liquidacionId}/aprobar")->assertStatus(403);
        $this->withHeaders($headersB)->getJson('/api/liquidaciones-cese')->assertOk()->assertJsonMissing(['id' => $liquidacionId]);
        $this->withHeaders($headersB)->getJson('/api/liquidaciones-cese/pendientes')->assertOk()->assertJsonMissing(['id' => $colaborador->id]);
        // Registrar con un colaborador de otra empresa: no existe en la empresa activa.
        $this->withHeaders($headersB)->postJson('/api/liquidaciones-cese', [
            'colaborador_id' => $colaborador->id, 'modo' => 'manual', 'sustento' => 'Intento cruzado',
            'conceptos' => [['codigo' => 'CTS_TRUNCA', 'monto' => 100]],
        ])->assertNotFound();
    }
}
