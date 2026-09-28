<?php

namespace Tests\Feature\PortalCliente;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PortalAsistenciaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal_cliente.enabled' => true]);
    }

    private function crearEmpresa(string $nombre): Empresa
    {
        return Empresa::factory()->create(['nombre_comercial' => $nombre, 'activa' => true]);
    }

    private function crearClienteDe(Empresa $empresa): User
    {
        $rol = Role::where('clave', 'cliente_empresa')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$empresa->id => ['role_id' => $rol->id]]);

        return $usuario;
    }

    private function headersPara(User $usuario): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
    }

    private function crearResultado(Empresa $empresa, Colaborador $colaborador, string $fecha, array $atributos = []): AsistenciaResultadoDiario
    {
        return AsistenciaResultadoDiario::create(array_merge([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'fecha' => $fecha,
            'tipo_dia' => 'laborable_presencial',
            'estado' => 'presente',
            'entrada_at' => "{$fecha} 08:00:00",
            'salida_at' => "{$fecha} 17:00:00",
            'minutos_trabajados' => 480,
            'procesado_at' => now(),
        ], $atributos));
    }

    private function crearIncidencia(Empresa $empresa, Colaborador $colaborador, AsistenciaResultadoDiario $resultado, array $atributos = []): AsistenciaIncidencia
    {
        return AsistenciaIncidencia::create(array_merge([
            'empresa_id' => $empresa->id,
            'resultado_diario_id' => $resultado->id,
            'colaborador_id' => $colaborador->id,
            'fecha' => $resultado->fecha,
            'tipo' => AsistenciaIncidencia::TIPO_FALTA,
            'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
        ], $atributos));
    }

    private function crearHoraExtra(Empresa $empresa, Colaborador $colaborador, AsistenciaResultadoDiario $resultado, array $atributos = []): AsistenciaHoraExtra
    {
        return AsistenciaHoraExtra::create(array_merge([
            'empresa_id' => $empresa->id,
            'resultado_diario_id' => $resultado->id,
            'colaborador_id' => $colaborador->id,
            'fecha' => $resultado->fecha,
            'minutos_observados' => 60,
            'tasa' => '25',
            'estado' => AsistenciaHoraExtra::ESTADO_PENDIENTE,
        ], $atributos));
    }

    private function crearPermiso(Empresa $empresa, Colaborador $colaborador, array $atributos = []): AsistenciaPermiso
    {
        return AsistenciaPermiso::create(array_merge([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'tipo' => 'personal',
            'fecha_inicio' => now()->toDateString(),
            'fecha_fin' => now()->toDateString(),
            'motivo' => 'Trámite personal',
            'estado' => 'aprobado',
        ], $atributos));
    }

    // 1 + 2. Aislamiento de colaboradores (listado, búsqueda, paginación)
    public function test_colaboradores_solo_incluye_los_de_la_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo, ['nombres' => 'Ana', 'apellidos' => 'Texajo']);
        $this->crearColaborador($livex, ['nombres' => 'Luis', 'apellidos' => 'Livex']);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['id']);
        $this->assertStringNotContainsString('Livex', json_encode($datos));
    }

    public function test_busqueda_no_revela_colaboradores_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $this->crearColaborador($texajo, ['nombres' => 'Ana', 'apellidos' => 'Texajo']);
        $this->crearColaborador($livex, ['nombres' => 'Luis', 'apellidos' => 'Livex']);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores?busqueda=Livex')->assertOk()->json('data');

        $this->assertCount(0, $datos);
    }

    // 3 + 15. 404 al acceder a un colaborador de otra empresa, en TODOS los sub-endpoints de perfil
    public function test_colaborador_de_otra_empresa_devuelve_404_en_todos_los_endpoints_de_perfil(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorLivex = $this->crearColaborador($livex);
        $usuario = $this->crearClienteDe($texajo);
        $headers = $this->headersPara($usuario);
        $rango = '?fecha_desde=2026-01-01&fecha_hasta=2026-01-15';

        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/calendario{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/marcaciones{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/incidencias{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/horas-extra{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/permisos{$rango}")->assertStatus(404);
        $this->withHeaders($headers)->getJson("/api/portal/asistencia/colaboradores/{$colaboradorLivex->id}/historial{$rango}")->assertStatus(404);
    }

    // 4. Manipular empresa_id no cambia los resultados
    public function test_empresa_id_en_query_no_afecta_resultados(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson("/api/portal/asistencia/colaboradores?empresa_id={$livex->id}")->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['id']);
    }

    // 5. Sin autenticación -> 401 con el portal habilitado
    public function test_sin_autenticacion_devuelve_401(): void
    {
        $this->getJson('/api/portal/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-15')->assertStatus(401);
        $this->getJson('/api/portal/asistencia/colaboradores')->assertStatus(401);
    }

    // 6. Sin el permiso correspondiente -> 403
    public function test_sin_permiso_correspondiente_devuelve_403(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        // 'solicitante' no tiene ningún permiso portal.* sincronizado.
        $rolSolicitante = Role::where('clave', 'solicitante')->firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $texajo->id]);
        $usuario->empresas()->syncWithoutDetaching([$texajo->id => ['role_id' => $rolSolicitante->id]]);
        $headers = $this->headersPara($usuario);

        $this->withHeaders($headers)->getJson('/api/portal/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-15')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/horas-extra?fecha_desde=2026-01-01&fecha_hasta=2026-01-15')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/permisos?fecha_desde=2026-01-01&fecha_hasta=2026-01-15')->assertStatus(403);
    }

    // 7. Portal deshabilitado -> 404
    public function test_portal_deshabilitado_devuelve_404(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['portal_cliente.enabled' => false]);
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearClienteDe($texajo);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')->assertStatus(404);
    }

    // 8. Fechas inválidas -> 422
    public function test_fechas_invalidas_devuelven_422(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearClienteDe($texajo);
        $headers = $this->headersPara($usuario);

        $this->withHeaders($headers)->getJson('/api/portal/asistencia/resumen?fecha_desde=no-es-fecha&fecha_hasta=2026-01-15')->assertStatus(422);
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/resumen?fecha_desde=2026-01-15&fecha_hasta=2026-01-01')->assertStatus(422);
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/resumen')->assertStatus(422);
    }

    // 9. Rango superior al máximo -> rechazado
    public function test_rango_superior_al_maximo_es_rechazado(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $colaborador = $this->crearColaborador($texajo);
        $usuario = $this->crearClienteDe($texajo);
        $headers = $this->headersPara($usuario);

        // calendario: tope 31 días (config('portal_cliente.rango_maximo_dias.calendario'))
        $this->withHeaders($headers)
            ->getJson("/api/portal/asistencia/colaboradores/{$colaborador->id}/calendario?fecha_desde=2026-01-01&fecha_hasta=2026-03-01")
            ->assertStatus(422);

        // listados: tope 92 días (config('portal_cliente.rango_maximo_dias.listado'))
        $this->withHeaders($headers)
            ->getJson('/api/portal/asistencia/incidencias?fecha_desde=2025-01-01&fecha_hasta=2026-06-01')
            ->assertStatus(422);
    }

    // 10. Paginación con límite máximo
    public function test_paginacion_tiene_limite_maximo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $usuario = $this->crearClienteDe($texajo);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores?per_page=500')
            ->assertStatus(422);
    }

    // 11. Resumen solo contabiliza información de TEXAJO
    public function test_resumen_solo_contabiliza_la_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $colaboradorLivex = $this->crearColaborador($livex);
        $this->crearResultado($texajo, $colaboradorTexajo, '2026-01-05', ['estado' => 'presente']);
        $this->crearResultado($livex, $colaboradorLivex, '2026-01-05', ['estado' => 'presente']);
        $this->crearResultado($livex, $colaboradorLivex, '2026-01-06', ['estado' => 'presente']);
        $usuario = $this->crearClienteDe($texajo);

        $resumen = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-31')
            ->assertOk()->json('data');

        $this->assertSame(1, $resumen['jornadas_presentes']);
        $this->assertSame(1, $resumen['colaboradores_activos']);
    }

    // Semántica: jornadas_presentes cuenta FILAS de AsistenciaResultadoDiario
    // (jornadas), no colaboradores distintos — un colaborador con 2 días
    // "presente" en el rango debe sumar 2, no 1.
    public function test_jornadas_presentes_cuenta_dias_no_colaboradores(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $colaborador = $this->crearColaborador($texajo);
        $this->crearResultado($texajo, $colaborador, '2026-01-05', ['estado' => 'presente']);
        $this->crearResultado($texajo, $colaborador, '2026-01-06', ['estado' => 'presente']);
        $usuario = $this->crearClienteDe($texajo);

        $resumen = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-31')
            ->assertOk()->json('data');

        $this->assertSame(2, $resumen['jornadas_presentes']);
        $this->assertSame(1, $resumen['colaboradores_activos']);
    }

    // 12. Incidencias solo pertenecen a TEXAJO
    public function test_incidencias_generales_solo_de_la_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $colaboradorLivex = $this->crearColaborador($livex);
        $resultadoTexajo = $this->crearResultado($texajo, $colaboradorTexajo, '2026-01-05', ['estado' => 'falta']);
        $resultadoLivex = $this->crearResultado($livex, $colaboradorLivex, '2026-01-05', ['estado' => 'falta']);
        $this->crearIncidencia($texajo, $colaboradorTexajo, $resultadoTexajo);
        $this->crearIncidencia($livex, $colaboradorLivex, $resultadoLivex);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/incidencias?fecha_desde=2026-01-01&fecha_hasta=2026-01-31')
            ->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['colaborador']['id']);
    }

    public function test_filtro_area_id_narrows_and_rejects_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo)->fresh(['area']);
        $colaboradorLivex = $this->crearColaborador($livex)->fresh(['area']);
        $resultadoTexajo = $this->crearResultado($texajo, $colaboradorTexajo, '2026-01-05', ['estado' => 'falta']);
        $this->crearIncidencia($texajo, $colaboradorTexajo, $resultadoTexajo);
        $usuario = $this->crearClienteDe($texajo);
        $headers = $this->headersPara($usuario);

        // area_id válido (de TEXAJO) acota correctamente.
        $datos = $this->withHeaders($headers)
            ->getJson("/api/portal/asistencia/incidencias?fecha_desde=2026-01-01&fecha_hasta=2026-01-31&area_id={$colaboradorTexajo->area_id}")
            ->assertOk()->json('data');
        $this->assertCount(1, $datos);

        // area_id de otra empresa (LIVEX) -> 422, no cero resultados en
        // silencio ni 200 con datos de otra empresa.
        $this->withHeaders($headers)
            ->getJson("/api/portal/asistencia/incidencias?fecha_desde=2026-01-01&fecha_hasta=2026-01-31&area_id={$colaboradorLivex->area_id}")
            ->assertStatus(422);

        // Ya no se acepta el nombre como filtro.
        $this->withHeaders($headers)
            ->getJson('/api/portal/asistencia/incidencias?fecha_desde=2026-01-01&fecha_hasta=2026-01-31&area='.urlencode($colaboradorTexajo->area->nombre))
            ->assertOk()
            ->assertJsonCount(1, 'data'); // el parámetro "area" simplemente se ignora (no está en las reglas), no filtra
    }

    public function test_filtro_sede_id_rechaza_sede_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorLivex = $this->crearColaborador($livex)->fresh(['sede']);
        $usuario = $this->crearClienteDe($texajo);

        $this->withHeaders($this->headersPara($usuario))
            ->getJson("/api/portal/asistencia/permisos?fecha_desde=2026-01-01&fecha_hasta=2026-01-31&sede_id={$colaboradorLivex->sede_id}")
            ->assertStatus(422);
    }

    // 13. Horas extra solo pertenecen a TEXAJO
    public function test_horas_extra_generales_solo_de_la_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $colaboradorLivex = $this->crearColaborador($livex);
        $resultadoTexajo = $this->crearResultado($texajo, $colaboradorTexajo, '2026-01-05');
        $resultadoLivex = $this->crearResultado($livex, $colaboradorLivex, '2026-01-05');
        $this->crearHoraExtra($texajo, $colaboradorTexajo, $resultadoTexajo);
        $this->crearHoraExtra($livex, $colaboradorLivex, $resultadoLivex);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/horas-extra?fecha_desde=2026-01-01&fecha_hasta=2026-01-31')
            ->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['colaborador']['id']);
    }

    // 14. Permisos solo pertenecen a TEXAJO
    public function test_permisos_generales_solo_de_la_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $colaboradorLivex = $this->crearColaborador($livex);
        $this->crearPermiso($texajo, $colaboradorTexajo, ['fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-05']);
        $this->crearPermiso($livex, $colaboradorLivex, ['fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-05']);
        $usuario = $this->crearClienteDe($texajo);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/permisos?fecha_desde=2026-01-01&fecha_hasta=2026-01-31')
            ->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['colaborador']['id']);
    }

    // Privacidad: un permiso tipo "medico" nunca expone el motivo de texto
    // libre (podría contener detalle clínico) — solo la categoría (tipo).
    public function test_permiso_medico_oculta_el_motivo_de_texto_libre(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $colaborador = $this->crearColaborador($texajo);
        $this->crearPermiso($texajo, $colaborador, [
            'tipo' => 'medico',
            'motivo' => 'Cita por arritmia cardiaca, reposo 3 días',
            'fecha_inicio' => '2026-01-05', 'fecha_fin' => '2026-01-05',
        ]);
        $this->crearPermiso($texajo, $colaborador, [
            'tipo' => 'personal',
            'motivo' => 'Trámite personal',
            'fecha_inicio' => '2026-01-06', 'fecha_fin' => '2026-01-06',
        ]);
        $usuario = $this->crearClienteDe($texajo);

        $respuesta = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/permisos?fecha_desde=2026-01-01&fecha_hasta=2026-01-31');

        $respuesta->assertOk();
        $cuerpo = $respuesta->getContent();
        $this->assertStringNotContainsString('arritmia', $cuerpo);
        $this->assertStringNotContainsString('cardiaca', $cuerpo);

        $datos = collect($respuesta->json('data'));
        $medico = $datos->firstWhere('tipo', 'medico');
        $personal = $datos->firstWhere('tipo', 'personal');

        $this->assertNull($medico['motivo']);
        $this->assertTrue($medico['motivo_oculto_por_privacidad']);
        $this->assertSame('Trámite personal', $personal['motivo']);
        $this->assertFalse($personal['motivo_oculto_por_privacidad']);
    }

    // 16. No existen rutas de escritura para este módulo
    public function test_no_existen_rutas_de_escritura(): void
    {
        $this->postJson('/api/portal/asistencia/resumen')->assertStatus(405);
        $this->postJson('/api/portal/asistencia/colaboradores')->assertStatus(405);
        $this->putJson('/api/portal/asistencia/incidencias')->assertStatus(405);
        $this->patchJson('/api/portal/asistencia/horas-extra')->assertStatus(405);
        $this->deleteJson('/api/portal/asistencia/permisos')->assertStatus(405);
    }

    // 17. Las respuestas no contienen datos sensibles
    public function test_respuestas_no_exponen_campos_sensibles(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $colaborador = $this->crearColaborador($texajo, [
            'banco' => 'BCP', 'numero_cuenta' => '1234567890', 'cci' => '00212300000000000000',
            'cuspp' => 'ABC-12345', 'direccion' => 'Av. Secreta 123', 'email' => 'privado@texajo.com',
        ]);
        $usuario = $this->crearClienteDe($texajo);
        $headers = $this->headersPara($usuario);

        $listado = $this->withHeaders($headers)->getJson('/api/portal/asistencia/colaboradores')->assertOk()->getContent();
        $perfil = $this->withHeaders($headers)
            ->getJson("/api/portal/asistencia/colaboradores/{$colaborador->id}?fecha_desde=2026-01-01&fecha_hasta=2026-01-15")
            ->assertOk()->getContent();

        foreach ([$listado, $perfil] as $cuerpo) {
            $this->assertStringNotContainsString('1234567890', $cuerpo);
            $this->assertStringNotContainsString('00212300000000000000', $cuerpo);
            $this->assertStringNotContainsString('BCP', $cuerpo);
            $this->assertStringNotContainsString('ABC-12345', $cuerpo);
            $this->assertStringNotContainsString('Secreta', $cuerpo);
            $this->assertStringNotContainsString('privado@texajo.com', $cuerpo);
            $this->assertStringNotContainsString('numero_cuenta', $cuerpo);
            $this->assertStringNotContainsString('cci', $cuerpo);
            $this->assertStringNotContainsString('salario', $cuerpo);
            $this->assertStringNotContainsString('cuspp', $cuerpo);
        }
    }

    // 18. Un usuario con dos empresas obtiene resultados según la empresa
    // activa — dos métodos independientes (no una sola prueba que cambia de
    // empresa a mitad de camino): Tymon\JWTAuth\JWTGuard::user() cachea el
    // usuario resuelto durante toda la vida del guard, que en un test de
    // Laravel persiste entre varias llamadas HTTP dentro del mismo método;
    // cambiar empresa_id y volver a autenticar en el mismo método no lo
    // invalida (esto solo afecta al harness de test — en producción cada
    // request real arranca un contenedor nuevo).
    public function test_cliente_ve_colaboradores_segun_su_empresa_activa_texajo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $colaboradorTexajo = $this->crearColaborador($texajo);
        $this->crearColaborador($livex);
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();

        $usuario = User::factory()->create(['empresa_id' => $texajo->id]);
        $usuario->empresas()->syncWithoutDetaching([
            $texajo->id => ['role_id' => $rolCliente->id],
            $livex->id => ['role_id' => $rolCliente->id],
        ]);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorTexajo->id, $datos[0]['id']);
    }

    public function test_el_mismo_usuario_ve_colaboradores_de_livex_cuando_esa_es_su_empresa_activa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        $livex = $this->crearEmpresa('LIVEX');
        $this->crearColaborador($texajo);
        $colaboradorLivex = $this->crearColaborador($livex);
        $rolCliente = Role::where('clave', 'cliente_empresa')->firstOrFail();

        $usuario = User::factory()->create(['empresa_id' => $livex->id]);
        $usuario->empresas()->syncWithoutDetaching([
            $texajo->id => ['role_id' => $rolCliente->id],
            $livex->id => ['role_id' => $rolCliente->id],
        ]);

        $datos = $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')->assertOk()->json('data');

        $this->assertCount(1, $datos);
        $this->assertSame($colaboradorLivex->id, $datos[0]['id']);
    }

    // 19. Administradores y módulos actuales mantienen su comportamiento
    public function test_administrador_conserva_su_acceso_a_los_modulos_actuales(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('username', 'test.user')->firstOrFail();
        $headers = $this->headersPara($admin);

        $this->withHeaders($headers)->getJson(
            '/api/asistencia/resumen?fecha_desde=2026-01-01&fecha_hasta=2026-01-31',
        )->assertOk();
        $this->withHeaders($headers)->getJson('/api/usuarios')->assertOk();
        // El admin también conserva acceso al portal (bypass general de permisos).
        $this->withHeaders($headers)->getJson('/api/portal/asistencia/colaboradores')->assertOk();
    }

    // 20. Sin N+1 evidentes en el listado principal de colaboradores
    public function test_listado_de_colaboradores_no_genera_n_mas_1(): void
    {
        $this->seed(DatabaseSeeder::class);
        $texajo = $this->crearEmpresa('TEXAJO');
        for ($i = 0; $i < 5; $i++) {
            $this->crearColaborador($texajo);
        }
        $usuario = $this->crearClienteDe($texajo);

        DB::enableQueryLog();
        $this->withHeaders($this->headersPara($usuario))
            ->getJson('/api/portal/asistencia/colaboradores')->assertOk();
        $totalConsultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Con eager loading de area/sede, el total de consultas no debe
        // escalar con la cantidad de colaboradores (5) — un N+1 real
        // hubiera generado 2 consultas adicionales POR colaborador.
        $this->assertLessThan(15, $totalConsultas, "Se ejecutaron {$totalConsultas} consultas — posible N+1.");
    }
}
