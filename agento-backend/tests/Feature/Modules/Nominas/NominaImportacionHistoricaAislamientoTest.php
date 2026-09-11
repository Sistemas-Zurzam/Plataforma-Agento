<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalleCorreccion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cubre las 4 situaciones de aislamiento pedidas en la revisión final del
 * Incremento 2: lote de otra empresa, detalle de otra empresa, detalle de
 * OTRO lote de la MISMA empresa, y usuario sin el permiso necesario.
 */
class NominaImportacionHistoricaAislamientoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private function autenticarComoAdmin(Empresa $empresa): array
    {
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $usuario->update(['empresa_id' => $empresa->id]);
        $token = JWTAuth::fromUser($usuario);
        Auth::forgetGuards();

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    /**
     * Usuario real sin ningún permiso `nominas.*`: la factory de User adjunta
     * automáticamente el rol "solicitante" (sin permisos asignados) a la
     * empresa activa — ver UserFactory::configure(). Es fundamental refrescar
     * el modelo antes de generar el JWT: justo tras `create()`, los atributos
     * que solo existen como default de columna (`activo`, `token_version`)
     * quedan en `null` en memoria, y `JWTAuth::fromUser()` los embebería como
     * `null` en el claim `token_version` — lo que JwtMiddleware rechaza con
     * 401 ("La sesión ya no es válida") antes de llegar siquiera a
     * EnsurePermission, camuflando un problema de permisos como uno de sesión.
     */
    private function autenticarSinPermiso(Empresa $empresa): array
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->refresh();
        $token = JWTAuth::fromUser($usuario);
        Auth::forgetGuards();

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_no_puede_ver_ni_aprobar_un_lote_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $lote = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresaA->id, 'estado' => 'validado']);

        [, $headersB] = $this->autenticarComoAdmin($empresaB);

        $this->withHeaders($headersB)->getJson("/api/nominas/importaciones-historicas/{$lote->id}")->assertStatus(403);
        // aprobar() lanza AuthorizationException (verificarPertenenciaLote) -> Laravel la renderiza como 403.
        $this->withHeaders($headersB)->patchJson("/api/nominas/importaciones-historicas/{$lote->id}/aprobar")->assertStatus(403);
    }

    public function test_no_puede_corregir_un_detalle_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $loteB = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresaB->id, 'estado' => 'validado']);
        $detalleDeB = NominaImportacionHistoricaDetalle::factory()->create(['importacion_id' => $loteB->id, 'empresa_id' => $empresaB->id]);

        [, $headersA] = $this->autenticarComoAdmin($empresaA);

        $respuesta = $this->withHeaders($headersA)->patchJson(
            "/api/nominas/importaciones-historicas/{$loteB->id}/detalles/{$detalleDeB->id}",
            ['cambios' => ['importe' => 100], 'motivo' => 'Intento no autorizado'],
        );
        $respuesta->assertStatus(403);
    }

    public function test_no_puede_corregir_un_detalle_de_otro_lote_de_la_misma_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        $loteUno = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $loteDos = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalleDelLoteDos = NominaImportacionHistoricaDetalle::factory()->create(['importacion_id' => $loteDos->id, 'empresa_id' => $empresa->id]);

        [, $headers] = $this->autenticarComoAdmin($empresa);

        // Combina el ID del lote UNO (autorizado) con un detalle que en
        // realidad pertenece al lote DOS -- debe rechazarse igual.
        $respuesta = $this->withHeaders($headers)->patchJson(
            "/api/nominas/importaciones-historicas/{$loteUno->id}/detalles/{$detalleDelLoteDos->id}/marcar-ignorado",
            ['motivo' => 'Intento combinando IDs de lotes distintos'],
        );
        $respuesta->assertStatus(403);
    }

    /**
     * Confirma con inspección de rutas que TODAS las rutas nuevas declaran
     * un middleware `permiso:` — complementa (no reemplaza) la prueba
     * funcional siguiente, que ejercita el HTTP real de punta a punta.
     */
    public function test_todas_las_rutas_de_importacion_historica_exigen_un_permiso(): void
    {
        $rutas = collect(Route::getRoutes())
            ->filter(fn ($ruta) => str_contains($ruta->uri(), 'nominas/importaciones-historicas'));

        $this->assertNotEmpty($rutas);

        foreach ($rutas as $ruta) {
            $tienePermiso = collect($ruta->gatherMiddleware())->contains(fn ($middleware) => str_starts_with($middleware, 'permiso:'));
            $this->assertTrue($tienePermiso, "La ruta {$ruta->methods()[0]} {$ruta->uri()} no exige ningún permiso.");
        }
    }

    /**
     * Prueba funcional real (no solo inspección de middleware): un usuario
     * autenticado válido, pero sin ningún permiso `nominas.*`, debe recibir
     * 403 en cada acción mutante y de lectura — y ninguna debe crear ni
     * modificar información.
     */
    public function test_usuario_sin_permiso_recibe_403_en_todas_las_acciones_y_no_modifica_nada(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = NominaImportacionHistorica::factory()->create([
            'empresa_id' => $empresa->id, 'estado' => 'validado', 'fecha_corte' => '2026-07-31',
        ]);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $lote->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'CTS', 'nombre_concepto_original' => 'CTS', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importe' => 500,
        ]);

        [, $headers] = $this->autenticarSinPermiso($empresa);

        $conteoLotesAntes = NominaImportacionHistorica::count();
        $conteoDetallesAntes = NominaImportacionHistoricaDetalle::count();
        $conteoCorreccionesAntes = NominaImportacionHistoricaDetalleCorreccion::count();

        $archivo = UploadedFile::fake()->create('antecedentes.xlsx', 10);
        $this->withHeaders($headers)->post('/api/nominas/importaciones-historicas', [
            'archivo' => $archivo, 'fecha_corte' => '2026-07-31',
        ])->assertStatus(403);

        $this->withHeaders($headers)->getJson("/api/nominas/importaciones-historicas/{$lote->id}")->assertStatus(403);

        $this->withHeaders($headers)->patchJson(
            "/api/nominas/importaciones-historicas/{$lote->id}/detalles/{$detalle->id}",
            ['cambios' => ['importe' => 999], 'motivo' => 'Intento sin permiso'],
        )->assertStatus(403);

        $this->withHeaders($headers)->patchJson(
            "/api/nominas/importaciones-historicas/{$lote->id}/detalles/{$detalle->id}/confirmar-cts",
            ['referencia_deposito' => 'Ref X', 'fecha_deposito' => '2026-05-15', 'motivo' => 'Intento sin permiso'],
        )->assertStatus(403);

        $this->withHeaders($headers)->patchJson("/api/nominas/importaciones-historicas/{$lote->id}/aprobar")->assertStatus(403);

        $this->withHeaders($headers)->postJson("/api/nominas/importaciones-historicas/{$lote->id}/aplicar")->assertStatus(403);

        $this->assertSame($conteoLotesAntes, NominaImportacionHistorica::count());
        $this->assertSame($conteoDetallesAntes, NominaImportacionHistoricaDetalle::count());
        $this->assertSame($conteoCorreccionesAntes, NominaImportacionHistoricaDetalleCorreccion::count());

        $detalle->refresh();
        $this->assertSame('observado', $detalle->clasificacion);
        $this->assertSame('observado', $detalle->estado_validacion);
        $this->assertNull($detalle->referencia_pago_confirmada);
        $this->assertNull($detalle->fecha_pago_confirmada);

        $lote->refresh();
        $this->assertSame('validado', $lote->estado);
    }
}
