<?php

namespace Tests\Feature\PortalCliente;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PortalContextoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Este archivo prueba el comportamiento normal de /portal/contexto
        // (200/403/401 según auth y permisos) — el feature flag se enciende
        // por defecto acá y solo se apaga explícitamente en los tests que
        // prueban justamente el flag apagado.
        config(['portal_cliente.enabled' => true]);
    }

    private function crearEmpresa(string $nombre): Empresa
    {
        return Empresa::factory()->create([
            'nombre_comercial' => $nombre,
            'razon_social' => "{$nombre} S.A.C.",
            'activa' => true,
        ]);
    }

    private function crearUsuarioConRol(Empresa $empresa, string $claveRol): User
    {
        $rol = Role::where('clave', $claveRol)->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => $rol->id]]);

        return $usuario;
    }

    private function headersPara(User $usuario): array
    {
        // fresh(): el token_version viaja en el JWT y JwtMiddleware lo
        // compara contra la fila real — una instancia recién creada en
        // memoria puede no tener el valor por defecto de la columna
        // (token_version) todavía cargado, lo que invalidaría el token.
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
    }

    public function test_cliente_de_livex_consulta_su_contexto(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/contexto')
            ->assertOk()
            ->json('data');

        $this->assertSame($livex->id, $datos['empresa']['id']);
        $this->assertSame('LIVEX', $datos['empresa']['nombre_comercial']);
        $this->assertSame('cliente_empresa', $datos['rol_actual']);
        $this->assertTrue($datos['es_portal_cliente']);
        $this->assertContains('portal.acceder', $datos['permisos']);
    }

    public function test_la_respuesta_no_menciona_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/contexto')->assertOk()->json('data');

        $this->assertSame($livex->id, $datos['empresa']['id']);
        $this->assertNotSame($texajo->id, $datos['empresa']['id']);
        $this->assertStringNotContainsString('TEXAJO', json_encode($datos));
    }

    public function test_no_puede_activar_una_empresa_a_la_que_no_pertenece(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');

        $this->withHeaders($this->headersPara($usuario))
            ->putJson("/api/empresas/{$texajo->id}/activar")
            ->assertStatus(403);

        $this->assertSame($livex->id, $usuario->fresh()->empresa_id);
    }

    public function test_cliente_sin_portal_acceder_recibe_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        // 'solicitante' no tiene ningún permiso sincronizado en el seeder.
        $usuario = $this->crearUsuarioConRol($livex, 'solicitante');

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/contexto')
            ->assertStatus(403);
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->getJson('/api/portal/contexto')->assertStatus(401);
    }

    public function test_rol_se_resuelve_segun_la_empresa_activa_no_globalmente(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $texajo = $this->crearEmpresa('TEXAJO');

        $usuario = User::factory()->create(['empresa_id' => $livex->id]);
        $usuario->empresas()->syncWithoutDetaching([
            $livex->id => ['role_id' => Role::where('clave', 'cliente_empresa')->value('id')],
            $texajo->id => ['role_id' => Role::where('clave', 'talento_cultura')->value('id')],
        ]);

        $meEnLivex = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/me')->assertOk()->json('data');
        $this->assertSame('cliente_empresa', $meEnLivex['role']);

        $usuario->update(['empresa_id' => $texajo->id]);
        $meEnTexajo = $this->withHeaders($this->headersPara($usuario->fresh()))
            ->getJson('/api/me')->assertOk()->json('data');
        $this->assertSame('talento_cultura', $meEnTexajo['role']);

        // En TEXAJO su rol (talento_cultura) no tiene portal.acceder: el
        // mismo usuario, la misma cuenta, queda bloqueado del portal ahí,
        // aunque en LIVEX sí tenía acceso — prueba que la resolución es por
        // empresa activa, no un flag global en el usuario.
        $this->withHeaders($this->headersPara($usuario->fresh()))
            ->getJson('/api/portal/contexto')->assertStatus(403);
    }

    public function test_administrador_conserva_su_acceso(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('username', 'test.user')->firstOrFail();
        $headers = $this->headersPara($admin);

        $this->withHeaders($headers)->getJson('/api/usuarios')->assertOk();
        $this->withHeaders($headers)->getJson('/api/portal/contexto')->assertOk();
    }

    public function test_ignora_empresa_id_enviado_por_query(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson("/api/portal/contexto?empresa_id={$texajo->id}")
            ->assertOk()->json('data');

        $this->assertSame($livex->id, $datos['empresa']['id']);
    }

    public function test_cliente_no_accede_a_rutas_administrativas(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');
        $headers = $this->headersPara($usuario);

        $this->withHeaders($headers)->getJson('/api/usuarios')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/ciclos-remunerativos')->assertStatus(403);
        $this->withHeaders($headers)->getJson(
            '/api/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-31',
        )->assertStatus(403);
    }

    public function test_rol_cliente_no_tiene_permisos_administrativos(): void
    {
        $this->seed(DatabaseSeeder::class);
        $claves = Role::where('clave', 'cliente_empresa')->firstOrFail()->permissions->pluck('clave');

        $this->assertTrue($claves->isNotEmpty());
        $this->assertTrue($claves->every(fn (string $clave) => str_starts_with($clave, 'portal.')));
        $this->assertFalse($claves->contains(fn (string $clave) => str_starts_with($clave, 'nominas.')));
        $this->assertFalse($claves->contains(fn (string $clave) => str_starts_with($clave, 'asistencia.')));
        $this->assertFalse($claves->contains(fn (string $clave) => str_starts_with($clave, 'usuarios.')));
    }

    public function test_flag_desactivada_bloquea_el_portal_aunque_el_usuario_este_autorizado(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['portal_cliente.enabled' => false]);
        $livex = $this->crearEmpresa('LIVEX');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');

        // 404, nunca 403: con el flag apagado la ruta debe comportarse como
        // si no existiera, no como "existe pero te la bloqueo".
        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/contexto')
            ->assertStatus(404);
    }

    public function test_flag_desactivada_no_afecta_el_inicio_de_sesion_administrativo(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['portal_cliente.enabled' => false]);
        $admin = User::where('username', 'test.user')->firstOrFail();
        $headers = $this->headersPara($admin);

        $this->withHeaders($headers)->getJson('/api/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/usuarios')->assertOk();
    }

    public function test_indicador_portal_cliente_habilitado_en_me_no_depende_de_empresa_id_recibido(): void
    {
        $this->seed(DatabaseSeeder::class);
        $livex = $this->crearEmpresa('LIVEX');
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearUsuarioConRol($livex, 'cliente_empresa');
        $headers = $this->headersPara($usuario);

        config(['portal_cliente.enabled' => true]);
        $conFlagOn = $this->withHeaders($headers)
            ->getJson("/api/me?empresa_id={$texajo->id}")->assertOk()->json('data');
        $this->assertTrue($conFlagOn['portal_cliente_habilitado']);

        config(['portal_cliente.enabled' => false]);
        $conFlagOff = $this->withHeaders($headers)
            ->getJson("/api/me?empresa_id={$texajo->id}")->assertOk()->json('data');
        $this->assertFalse($conFlagOff['portal_cliente_habilitado']);

        // En ambos casos /me sigue devolviendo la empresa real del usuario
        // (LIVEX), nunca la del query string.
        $this->assertSame($livex->id, $conFlagOn['empresa']['id']);
        $this->assertSame($livex->id, $conFlagOff['empresa']['id']);
    }
}
