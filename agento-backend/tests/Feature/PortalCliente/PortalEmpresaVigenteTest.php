<?php

namespace Tests\Feature\PortalCliente;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * EnsurePortalEmpresaVigente — defensa explícita y centralizada para todo
 * /api/portal/*, independiente de EnsurePermission (currentRole() nulo) y
 * de simplemente leer $user->empresa.
 */
class PortalEmpresaVigenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal_cliente.enabled' => true]);
    }

    private function crearEmpresa(string $nombre, array $atributos = []): Empresa
    {
        return Empresa::factory()->create(array_merge(['nombre_comercial' => $nombre, 'activa' => true], $atributos));
    }

    private function headersPara(User $usuario): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
    }

    public function test_empresa_id_sin_relacion_en_empresa_user_recibe_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaSinVinculo = $this->crearEmpresa('LIVEX');
        // El usuario nunca tuvo una fila en empresa_user para esta empresa —
        // solo se le fuerza el puntero de empresa activa directamente en BD,
        // simulando una inconsistencia (ej. manipulación directa o un bug
        // en otro flujo), no algo alcanzable hoy vía la API normal.
        $usuario = User::factory()->create(['empresa_id' => $empresaSinVinculo->id]);
        // El factory igual adjunta una fila por defecto (rol 'solicitante')
        // en su empresa activa — la removemos para simular el caso real.
        $usuario->empresas()->detach($empresaSinVinculo->id);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')
            ->assertStatus(403);
    }

    public function test_relacion_eliminada_despues_de_iniciar_sesion_recibe_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = $this->crearEmpresa('TEXAJO');
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => $rolCliente->id]]);
        $headers = $this->headersPara($usuario);

        // Todavía autorizado.
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/colaboradores')->assertOk();

        // Un administrador quita al usuario de la empresa (borra la fila del
        // pivote) — la sesión/JWT sigue siendo válida, pero el acceso ya no.
        $usuario->empresas()->detach($empresa->id);

        $this->withHeaders($headers)->getJson('/api/portal/asistencia/colaboradores')->assertStatus(403);
    }

    public function test_empresa_inactiva_recibe_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = $this->crearEmpresa('TEXAJO');
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => $rolCliente->id]]);

        // La empresa se desactiva (ej. baja del cliente) mientras la sesión
        // del usuario sigue activa.
        $empresa->update(['activa' => false]);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')
            ->assertStatus(403);
    }

    public function test_rol_en_el_pivote_sin_acceso_al_portal_recibe_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = $this->crearEmpresa('TEXAJO');
        // 'solicitante' existe y tiene una relación válida en empresa_user
        // (pasa EnsurePortalEmpresaVigente), pero no tiene ningún permiso
        // portal.* sincronizado (falla en permiso:portal.asistencia.ver).
        $rolSolicitante = Role::where('clave', 'solicitante')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => $rolSolicitante->id]]);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')
            ->assertStatus(403);
    }

    public function test_usuario_con_dos_empresas_validas_accede_a_la_primera(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $texajo->id]);
        $usuario->empresas()->syncWithoutDetaching([
            $texajo->id => ['role_id' => $rolCliente->id],
            $livex->id => ['role_id' => $rolCliente->id],
        ]);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')
            ->assertOk();
    }

    // Método aparte (no un segundo request en el mismo test) — ver la nota
    // en PortalAsistenciaTest sobre el cacheo de JWTGuard::user() durante
    // la vida de un mismo método de test.
    public function test_usuario_con_dos_empresas_validas_accede_a_la_segunda_cuando_esa_es_la_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $livex->id]);
        $usuario->empresas()->syncWithoutDetaching([
            $texajo->id => ['role_id' => $rolCliente->id],
            $livex->id => ['role_id' => $rolCliente->id],
        ]);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')
            ->assertOk();
    }
}
