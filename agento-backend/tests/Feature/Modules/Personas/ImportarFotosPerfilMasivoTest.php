<?php

namespace Tests\Feature\Modules\Personas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\ColaboradorDocumento;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Importación masiva de fotos de perfil (Gestión de Personas → cargar fotos):
 * cada archivo se empareja por su nombre (sin extensión) contra
 * numero_documento — nunca por un identificador enviado explícitamente.
 */
class ImportarFotosPerfilMasivoTest extends TestCase
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

    private function autenticarSinPermiso(Empresa $empresa): array
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->refresh();
        $token = JWTAuth::fromUser($usuario);
        Auth::forgetGuards();

        return [$usuario, ['Authorization' => "Bearer {$token}"]];
    }

    public function test_asocia_la_foto_al_colaborador_cuyo_dni_coincide(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        [, $headers] = $this->autenticarComoAdmin($empresa);
        $colaborador = $this->crearColaborador($empresa, ['numero_documento' => '70826733']);

        $respuesta = $this->withHeaders($headers)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [UploadedFile::fake()->image('70826733.jpg', 200, 200)],
        ]);

        $respuesta->assertOk()->assertJsonPath('data.actualizados', 1)
            ->assertJsonPath('data.sin_coincidencia', [])
            ->assertJsonPath('data.duplicados', []);
        $this->assertTrue(
            ColaboradorDocumento::where('colaborador_id', $colaborador->id)->where('tipo', 'foto_perfil')->exists()
        );
    }

    public function test_empareja_usando_solo_el_dni_cuando_el_archivo_incluye_el_nombre(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        [, $headers] = $this->autenticarComoAdmin($empresa);
        $colaborador = $this->crearColaborador($empresa, ['numero_documento' => '006884947']);

        $respuesta = $this->withHeaders($headers)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [UploadedFile::fake()->image('006884947-GIOVANNY JOSE FALCO GARCIA.jpg', 200, 200)],
        ]);

        $respuesta->assertOk()->assertJsonPath('data.actualizados', 1)
            ->assertJsonPath('data.sin_coincidencia', []);
        $this->assertTrue(
            ColaboradorDocumento::where('colaborador_id', $colaborador->id)->where('tipo', 'foto_perfil')->exists()
        );
    }

    public function test_reporta_sin_coincidencia_cuando_ningun_colaborador_tiene_ese_dni(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        [, $headers] = $this->autenticarComoAdmin($empresa);

        $respuesta = $this->withHeaders($headers)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [UploadedFile::fake()->image('99999999.jpg', 200, 200)],
        ]);

        $respuesta->assertOk()->assertJsonPath('data.actualizados', 0)
            ->assertJsonPath('data.sin_coincidencia', ['99999999']);
    }

    public function test_no_empareja_un_colaborador_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $this->crearColaborador($empresaB, ['numero_documento' => '70826733']);
        [, $headersA] = $this->autenticarComoAdmin($empresaA);

        $respuesta = $this->withHeaders($headersA)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [UploadedFile::fake()->image('70826733.jpg', 200, 200)],
        ]);

        $respuesta->assertOk()->assertJsonPath('data.actualizados', 0)
            ->assertJsonPath('data.sin_coincidencia', ['70826733']);
    }

    public function test_reporta_duplicados_cuando_el_lote_repite_el_mismo_dni(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        [, $headers] = $this->autenticarComoAdmin($empresa);
        $this->crearColaborador($empresa, ['numero_documento' => '70826733']);

        $respuesta = $this->withHeaders($headers)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [
                UploadedFile::fake()->image('70826733.jpg', 200, 200),
                UploadedFile::fake()->image('70826733.jpg', 200, 200),
            ],
        ]);

        $respuesta->assertOk()->assertJsonPath('data.actualizados', 1)
            ->assertJsonPath('data.duplicados', ['70826733']);
    }

    public function test_usuario_sin_permiso_no_puede_importar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::factory()->create();
        [, $headers] = $this->autenticarSinPermiso($empresa);

        $respuesta = $this->withHeaders($headers)->postJson('/api/colaboradores/fotos-perfil/importar-masivo', [
            'archivos' => [UploadedFile::fake()->image('70826733.jpg', 200, 200)],
        ]);

        $respuesta->assertStatus(403);
    }
}
