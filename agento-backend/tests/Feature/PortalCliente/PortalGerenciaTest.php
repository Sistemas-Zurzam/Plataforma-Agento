<?php

namespace Tests\Feature\PortalCliente;

use App\Models\User;
use App\Modules\Asistencia\Application\DecidirHoraExtraGerencia;
use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PropuestaConcepto;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\SolicitudCese;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Portal de Empresa para gerencia (GER): alcance por empresa + áreas,
 * colaboradores activos, proyección sin datos económicos y circuitos con
 * RR. HH. (horas extra, permisos, planificación, bonos y ceses).
 */
class PortalGerenciaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private Colaborador $propio;

    private Colaborador $otraArea;

    private Colaborador $inactivo;

    private Colaborador $otraEmpresa;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal_cliente.enabled' => true]);
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'TEXAJO', 'activa' => true]);
        $this->propio = $this->crearColaborador($this->empresa);
        $this->otraArea = $this->crearColaborador($this->empresa);
        $this->inactivo = $this->crearColaborador($this->empresa, ['area_id' => $this->propio->area_id, 'activo' => false]);
        $this->otraEmpresa = $this->crearColaborador(Empresa::factory()->create(['activa' => true]));
        $this->gerente = $this->crearGerente($this->propio->area_id);
    }

    private function crearGerente(?int $areaId): User
    {
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id, 'area_id' => $areaId]);
        $usuario->empresas()->syncWithoutDetaching([$this->empresa->id => ['role_id' => Role::where('clave', 'gerencia')->value('id')]]);

        return $usuario->fresh();
    }

    private function rrhh(): User
    {
        $admin = User::where('username', 'test.user')->firstOrFail();
        $admin->update(['empresa_id' => $this->empresa->id]);

        return $admin->fresh();
    }

    private function como(User $usuario): self
    {
        // El guard JWT persiste entre requests de una misma prueba.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())]);
    }

    private function horaExtra(Colaborador $colaborador, array $atributos = []): AsistenciaHoraExtra
    {
        return AsistenciaHoraExtra::withoutGlobalScopes()->create(array_merge([
            'empresa_id' => $colaborador->empresa_id, 'resultado_diario_id' => null, 'origen' => 'asistencia',
            'colaborador_id' => $colaborador->id, 'fecha' => now()->subDays(2)->toDateString(),
            'minutos_observados' => 120, 'minutos_solicitados' => 120, 'minutos_aprobados' => 0,
            'tasa' => '25', 'estado' => 'pendiente',
        ], $atributos));
    }

    private function decidir(AsistenciaHoraExtra $horaExtra, array $datos)
    {
        $fresca = AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id);

        return $this->como($this->gerente)->patchJson("/api/portal/horas-extra/{$horaExtra->id}/decision", array_merge([
            'motivo' => 'Trabajo de cierre de inventario', 'version' => DecidirHoraExtraGerencia::version($fresca),
        ], $datos));
    }

    /**
     * Por defecto cubre desde hace 10 días hasta fin de mes (incluye las
     * horas extra de prueba aunque hoy sea día 1 o 2).
     */
    private function ciclo(string $estado = 'abierto', ?string $desde = null, ?string $hasta = null): CicloRemunerativo
    {
        $desde ??= now()->subDays(10)->startOfMonth()->toDateString();
        $hasta ??= now()->endOfMonth()->toDateString();

        return CicloRemunerativo::create([
            'empresa_id' => $this->empresa->id, 'nombre' => 'Ciclo '.Str::random(4), 'periodicidad' => 'mensual',
            'fecha_inicio' => $desde, 'fecha_fin' => $hasta, 'fecha_corte_asistencia' => $hasta, 'fecha_pago' => $hasta,
            'estado' => $estado,
        ]);
    }

    private function pdf(string $nombre = 'carta.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF\n");
    }

    private function hoyNegocio(): string
    {
        return Carbon::now(config('portal_cliente.zona_horaria_negocio'))->toDateString();
    }

    // ---------------------------------------------------------------- Alcance

    public function test_gerente_solo_ve_colaboradores_activos_de_sus_areas(): void
    {
        $ids = collect($this->como($this->gerente)->getJson('/api/portal/asistencia/colaboradores')->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$this->propio->id], $ids->all());

        $mensual = $this->como($this->gerente)->getJson('/api/portal/asistencia/equipo-mensual?mes='.now()->format('Y-m'))->assertOk();
        $this->assertEquals([$this->propio->id], collect($mensual->json('data'))->pluck('id')->all());
        $this->assertCount(now()->daysInMonth, $mensual->json('dias'));
    }

    public function test_gerente_sin_areas_no_recibe_acceso_global(): void
    {
        $sinAreas = $this->crearGerente(null);

        $this->como($sinAreas)->getJson('/api/portal/asistencia/colaboradores')->assertOk()->assertJsonCount(0, 'data');
        $this->como($sinAreas)->getJson('/api/portal/contexto')->assertOk()
            ->assertJsonPath('data.alcance', 'areas')->assertJsonCount(0, 'data.areas');
    }

    public function test_acceso_directo_a_ids_fuera_del_alcance_devuelve_404(): void
    {
        $rango = '?fecha_desde='.now()->subDays(5)->toDateString().'&fecha_hasta='.now()->toDateString();
        foreach ([$this->otraArea, $this->inactivo] as $ajeno) {
            $this->como($this->gerente)->getJson("/api/portal/asistencia/colaboradores/{$ajeno->id}{$rango}")->assertNotFound();
            $this->como($this->gerente)->getJson("/api/portal/asistencia/colaboradores/{$ajeno->id}/calendario{$rango}")->assertNotFound();
        }
        $this->como($this->gerente)->getJson("/api/portal/asistencia/colaboradores/{$this->otraEmpresa->id}{$rango}")->assertNotFound();

        // area_id ajeno en filtros → 422, nunca cero filas silenciosas.
        $this->como($this->gerente)->getJson('/api/portal/asistencia/equipo-mensual?mes='.now()->format('Y-m')."&area_id={$this->otraArea->area_id}")
            ->assertUnprocessable()->assertJsonValidationErrors('area_id');
    }

    public function test_contadores_y_listados_usan_el_mismo_alcance(): void
    {
        $this->horaExtra($this->propio);
        $this->horaExtra($this->otraArea);
        $this->horaExtra($this->inactivo);

        $inicio = $this->como($this->gerente)->getJson('/api/portal/inicio')->assertOk()->json('data.horas_extra');
        $this->assertSame(1, $inicio['por_aprobar']);

        $listado = $this->como($this->gerente)->getJson("/api/portal/asistencia/horas-extra?fecha_desde={$inicio['fecha_desde']}&fecha_hasta={$inicio['fecha_hasta']}&estado=pendiente")
            ->assertOk();
        $this->assertSame(1, $listado->json('meta.total'));
    }

    public function test_busqueda_general_no_se_desborda_por_coincidencias_en_otras_empresas(): void
    {
        $this->otraEmpresa->update(['apellidos' => 'Coincidencia']);
        $this->horaExtra($this->propio);
        $desde = now()->subDays(10)->toDateString();
        $hasta = now()->toDateString();

        // Antes del fix, el orWhere sin agrupar dentro de whereHas devolvía
        // TODO el alcance si cualquier colaborador de cualquier empresa coincidía.
        $this->como($this->gerente)->getJson("/api/portal/asistencia/horas-extra?fecha_desde={$desde}&fecha_hasta={$hasta}&busqueda=Coincidencia")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_respuestas_del_portal_no_exponen_datos_economicos(): void
    {
        $this->horaExtra($this->propio);
        $desde = now()->subDays(10)->toDateString();
        $hasta = now()->toDateString();

        $cuerpos = [
            $this->como($this->gerente)->getJson('/api/portal/asistencia/colaboradores')->getContent(),
            $this->como($this->gerente)->getJson("/api/portal/asistencia/horas-extra?fecha_desde={$desde}&fecha_hasta={$hasta}")->getContent(),
            $this->como($this->gerente)->getJson('/api/portal/asistencia/equipo-mensual?mes='.now()->format('Y-m'))->getContent(),
            $this->como($this->gerente)->getJson('/api/portal/inicio')->getContent(),
        ];
        foreach ($cuerpos as $cuerpo) {
            foreach (['salario', 'sueldo', 'boleta', 'importe', 'numero_cuenta', 'cci', 'cuspp', 'afp', 'banco'] as $prohibido) {
                $this->assertStringNotContainsStringIgnoringCase("\"{$prohibido}", $cuerpo, "La respuesta expone '{$prohibido}'.");
            }
        }
    }

    public function test_gerente_no_puede_eludir_el_portal_con_endpoints_internos(): void
    {
        $horaExtra = $this->horaExtra($this->propio);
        $ciclo = $this->ciclo();

        $this->como($this->gerente)->patchJson("/api/asistencia/horas-extra/{$horaExtra->id}", ['accion' => 'aprobar', 'motivo' => 'x'])->assertForbidden();
        $this->como($this->gerente)->patchJson("/api/colaboradores/{$this->propio->id}/cesar", ['fecha_cese' => now()->toDateString(), 'motivo_cese' => 'x'])->assertForbidden();
        $this->como($this->gerente)->getJson('/api/asistencia/horas-extra')->assertForbidden();
        $this->como($this->gerente)->getJson("/api/ciclos-remunerativos/{$ciclo->id}/colaboradores/{$this->propio->id}/conceptos")->assertForbidden();
        $this->como($this->gerente)->getJson('/api/propuestas-conceptos')->assertForbidden();
        $this->como($this->gerente)->getJson('/api/solicitudes-cese')->assertForbidden();
        $this->como($this->gerente)->postJson('/api/asistencia/permisos', [])->assertForbidden();
    }

    public function test_cliente_empresa_no_hereda_permisos_de_escritura_de_gerencia(): void
    {
        $cliente = User::factory()->create(['empresa_id' => $this->empresa->id]);
        $cliente->empresas()->syncWithoutDetaching([$this->empresa->id => ['role_id' => Role::where('clave', 'cliente_empresa')->value('id')]]);

        $this->como($cliente)->postJson('/api/portal/bonos', [])->assertForbidden();
        $this->como($cliente)->postJson('/api/portal/ceses', [])->assertForbidden();
        $this->como($cliente)->putJson('/api/portal/planificacion', [])->assertForbidden();
        // Conserva su alcance original de toda la empresa (incluye otras áreas e inactivos).
        $this->como($cliente)->getJson('/api/portal/asistencia/colaboradores')->assertOk()->assertJsonCount(3, 'data');
    }

    // ----------------------------------------------------------- Horas extra

    public function test_aprobacion_total_de_gerencia_queda_pendiente_de_rrhh_y_audita(): void
    {
        $horaExtra = $this->horaExtra($this->propio);

        $this->decidir($horaExtra, ['accion' => 'aprobar_total'])->assertOk()
            ->assertJsonPath('data.estado', 'aprobado_gerencia')
            ->assertJsonPath('data.minutos_aprobados_gerencia', 120)
            ->assertJsonPath('data.siguiente_responsable', 'rrhh')
            ->assertJsonPath('data.tasa_etiqueta', '25%');

        $fresca = AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id);
        $this->assertSame(0, (int) $fresca->minutos_aprobados, 'Gerencia nunca valida para Nómina.');
        $this->assertSame($this->gerente->id, $fresca->gerencia_resuelto_por);
        $this->assertTrue(AsistenciaAuditoria::withoutGlobalScopes()
            ->where('accion', 'hora_extra_gerencia_aprobar_total')->where('usuario_id', $this->gerente->id)->exists());
    }

    public function test_aprobacion_parcial_respeta_limites_de_minutos(): void
    {
        $horaExtra = $this->horaExtra($this->propio);

        $this->decidir($horaExtra, ['accion' => 'aprobar_parcial', 'minutos' => 0])->assertUnprocessable();
        $this->decidir($horaExtra, ['accion' => 'aprobar_parcial', 'minutos' => 121])->assertUnprocessable()->assertJsonValidationErrors('minutos');
        $this->decidir($horaExtra, ['accion' => 'aprobar_parcial'])->assertUnprocessable()->assertJsonValidationErrors('minutos');
        $this->decidir($horaExtra, ['accion' => 'aprobar_parcial', 'minutos' => 90])->assertOk()
            ->assertJsonPath('data.minutos_aprobados_gerencia', 90);
    }

    public function test_rechazo_de_gerencia_no_conserva_minutos(): void
    {
        $horaExtra = $this->horaExtra($this->propio);

        $this->decidir($horaExtra, ['accion' => 'rechazar'])->assertOk()->assertJsonPath('data.estado', 'rechazado');
        $fresca = AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id);
        $this->assertSame(0, (int) $fresca->minutos_aprobados);
        $this->assertNull($fresca->minutos_aprobados_gerencia);
    }

    public function test_reintento_o_decision_repetida_devuelve_conflicto(): void
    {
        $horaExtra = $this->horaExtra($this->propio);
        $version = DecidirHoraExtraGerencia::version(AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id));
        $cuerpo = ['accion' => 'aprobar_total', 'motivo' => 'Cierre de inventario', 'version' => $version];

        $this->como($this->gerente)->patchJson("/api/portal/horas-extra/{$horaExtra->id}/decision", $cuerpo)->assertOk();
        $this->como($this->gerente)->patchJson("/api/portal/horas-extra/{$horaExtra->id}/decision", $cuerpo)->assertStatus(409);
    }

    public function test_registro_modificado_por_reprocesamiento_exige_recargar(): void
    {
        $horaExtra = $this->horaExtra($this->propio);
        $versionVieja = DecidirHoraExtraGerencia::version(AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id));
        // Simula un reprocesamiento que cambió el tiempo observado.
        AsistenciaHoraExtra::withoutGlobalScopes()->whereKey($horaExtra->id)->update(['minutos_observados' => 60, 'minutos_solicitados' => 60]);

        $this->como($this->gerente)->patchJson("/api/portal/horas-extra/{$horaExtra->id}/decision", [
            'accion' => 'aprobar_total', 'motivo' => 'Cierre de inventario', 'version' => $versionVieja,
        ])->assertStatus(409);
        $this->assertSame('pendiente', AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id)->estado);
    }

    public function test_hora_extra_de_periodo_cerrado_o_ciclo_cerrado_esta_bloqueada(): void
    {
        $enPeriodo = $this->horaExtra($this->propio);
        AsistenciaPeriodo::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'fecha_inicio' => $enPeriodo->fecha->toDateString(),
            'fecha_fin' => $enPeriodo->fecha->toDateString(), 'estado' => 'cerrado',
        ]);
        $this->decidir($enPeriodo, ['accion' => 'aprobar_total'])->assertUnprocessable();

        AsistenciaPeriodo::withoutGlobalScopes()->delete();
        $this->ciclo('cerrado');
        $this->decidir($enPeriodo, ['accion' => 'aprobar_total'])->assertUnprocessable();
    }

    public function test_hora_extra_de_otra_area_o_validada_no_es_decidible(): void
    {
        $ajena = $this->horaExtra($this->otraArea);
        $this->decidir($ajena, ['accion' => 'aprobar_total'])->assertNotFound();

        $validada = $this->horaExtra($this->propio, ['estado' => 'aprobado', 'minutos_aprobados' => 120]);
        $this->decidir($validada, ['accion' => 'rechazar'])->assertStatus(409);
    }

    public function test_gerente_no_aprueba_sus_propias_horas_extra(): void
    {
        $this->propio->update(['email' => $this->gerente->email]);
        $horaExtra = $this->horaExtra($this->propio);

        $this->decidir($horaExtra, ['accion' => 'aprobar_total'])->assertForbidden();
    }

    public function test_rrhh_valida_sin_superar_lo_aprobado_por_gerencia(): void
    {
        $horaExtra = $this->horaExtra($this->propio);
        $this->decidir($horaExtra, ['accion' => 'aprobar_parcial', 'minutos' => 60])->assertOk();

        $this->como($this->rrhh())->patchJson("/api/asistencia/horas-extra/{$horaExtra->id}", [
            'accion' => 'aprobar', 'motivo' => 'Validación RR. HH.', 'minutos_aprobados' => 90,
        ])->assertUnprocessable();
        $this->como($this->rrhh())->patchJson("/api/asistencia/horas-extra/{$horaExtra->id}", [
            'accion' => 'aprobar', 'motivo' => 'Validación RR. HH.', 'minutos_aprobados' => 45,
        ])->assertOk();

        $fresca = AsistenciaHoraExtra::withoutGlobalScopes()->findOrFail($horaExtra->id);
        $this->assertSame('aprobado', $fresca->estado);
        $this->assertSame(45, (int) $fresca->minutos_aprobados);
    }

    public function test_aprobado_gerencia_bloquea_el_cierre_del_periodo_como_pendiente(): void
    {
        $horaExtra = $this->horaExtra($this->propio);
        $this->decidir($horaExtra, ['accion' => 'aprobar_total'])->assertOk();

        $this->assertSame(1, AsistenciaHoraExtra::withoutGlobalScopes()
            ->whereIn('estado', AsistenciaHoraExtra::ESTADOS_PENDIENTES_RRHH)->count());
    }

    // -------------------------------------------------------------- Permisos

    private function datosPermiso(array $extra = []): array
    {
        return array_merge([
            'colaborador_id' => $this->propio->id, 'tipo' => 'personal', 'modalidad' => 'dia_completo',
            'fecha_inicio' => now()->addDays(3)->toDateString(), 'fecha_fin' => now()->addDays(4)->toDateString(),
            'con_goce' => true, 'motivo' => 'Trámite familiar',
        ], $extra);
    }

    public function test_permiso_solicitado_con_origen_y_estado_asignados_por_servidor(): void
    {
        $resultado = AsistenciaResultadoDiario::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'colaborador_id' => $this->propio->id, 'fecha' => now()->subDay()->toDateString(),
            'tipo_dia' => 'laborable_presencial', 'estado' => 'falta', 'procesado_at' => now(),
        ]);
        $incidencia = AsistenciaIncidencia::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'resultado_diario_id' => $resultado->id, 'colaborador_id' => $this->propio->id,
            'fecha' => $resultado->fecha, 'tipo' => 'falta', 'estado' => 'pendiente',
        ]);

        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso([
            'fecha_inicio' => now()->subDay()->toDateString(), 'fecha_fin' => now()->subDay()->toDateString(),
            'estado' => 'aprobado', 'origen' => 'asistencia',
        ]))->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.origen', 'portal')
            ->assertJsonPath('data.siguiente_responsable', 'rrhh');

        $permiso = AsistenciaPermiso::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->gerente->id, $permiso->registrado_por);
        // Solicitar nunca justifica la incidencia.
        $this->assertSame('pendiente', $incidencia->fresh()->estado);
        $this->assertSame('falta', $resultado->fresh()->estado);
    }

    public function test_permiso_valida_solapamiento_modalidad_y_alcance(): void
    {
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso())->assertCreated();
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso())
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_inicio');
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso(['modalidad' => 'por_horas']))
            ->assertUnprocessable()->assertJsonValidationErrors('modalidad');
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso(['tipo' => 'vacaciones']))
            ->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso(['colaborador_id' => $this->inactivo->id]))
            ->assertNotFound();
        $this->como($this->gerente)->postJson('/api/portal/permisos', $this->datosPermiso(['colaborador_id' => $this->otraArea->id]))
            ->assertNotFound();
    }

    // --------------------------------------------------------- Planificación

    public function test_planificacion_aplica_lote_con_origen_portal(): void
    {
        $this->como($this->gerente)->putJson('/api/portal/planificacion', ['cambios' => [
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-03', 'tipo' => 'descanso', 'tipo_anterior' => null],
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-04', 'tipo' => 'home_office', 'tipo_anterior' => null],
        ]])->assertOk()->assertJsonPath('data.aplicados', 2);

        $this->assertSame(2, ColaboradorCalendarioDia::where('colaborador_id', $this->propio->id)->where('origen', 'portal')->count());
    }

    public function test_planificacion_masiva_sin_cambios_parciales_ante_conflictos(): void
    {
        $respuesta = $this->como($this->gerente)->putJson('/api/portal/planificacion', ['cambios' => [
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-03', 'tipo' => 'descanso', 'tipo_anterior' => null],
            // Feriado: solo lectura.
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-12-25', 'tipo' => 'descanso', 'tipo_anterior' => null],
            // Concurrencia: el usuario creyó ver otro tipo.
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-05', 'tipo' => 'descanso', 'tipo_anterior' => 'home_office'],
        ]])->assertStatus(409);

        $this->assertCount(2, $respuesta->json('conflictos'));
        $this->assertSame(0, ColaboradorCalendarioDia::where('colaborador_id', $this->propio->id)->count(), 'No debe aplicarse ningún cambio.');
    }

    public function test_planificacion_rechaza_colaborador_ajeno_y_periodo_cerrado(): void
    {
        $this->como($this->gerente)->putJson('/api/portal/planificacion', ['cambios' => [
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-03', 'tipo' => 'descanso', 'tipo_anterior' => null],
            ['colaborador_id' => $this->otraArea->id, 'fecha' => '2026-11-03', 'tipo' => 'descanso', 'tipo_anterior' => null],
        ]])->assertNotFound();

        AsistenciaPeriodo::withoutGlobalScopes()->create([
            'empresa_id' => $this->empresa->id, 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30', 'estado' => 'cerrado',
        ]);
        $this->como($this->gerente)->putJson('/api/portal/planificacion', ['cambios' => [
            ['colaborador_id' => $this->propio->id, 'fecha' => '2026-11-03', 'tipo' => 'descanso', 'tipo_anterior' => null],
        ]])->assertStatus(409);

        $this->assertSame(0, ColaboradorCalendarioDia::where('colaborador_id', $this->propio->id)->count());
    }

    // ------------------------------------------------------------------ Bonos

    private function datosBono(CicloRemunerativo $ciclo, array $extra = []): array
    {
        return array_merge([
            'colaborador_id' => $this->propio->id, 'ciclo_id' => $ciclo->id, 'tipo' => 'comision',
            'monto' => '350.50', 'motivo' => 'Meta de ventas', 'clave_idempotencia' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_propuesta_de_bono_es_idempotente_y_queda_propuesta(): void
    {
        $datos = $this->datosBono($this->ciclo());

        $primera = $this->como($this->gerente)->postJson('/api/portal/bonos', $datos + ['estado' => 'validado', 'origen' => 'x'])
            ->assertCreated()->assertJsonPath('data.estado', 'propuesto')->assertJsonPath('data.origen', 'portal')
            ->assertJsonPath('data.monto', '350.50');
        $this->como($this->gerente)->postJson('/api/portal/bonos', $datos)->assertOk()->assertJsonPath('data.id', $primera->json('data.id'));
        // Otra propuesta legítima (clave nueva) sí se crea.
        $this->como($this->gerente)->postJson('/api/portal/bonos', array_merge($datos, ['clave_idempotencia' => (string) Str::uuid()]))->assertCreated();

        $this->assertSame(2, PropuestaConcepto::count());
        $this->assertSame(0, ColaboradorConceptoPeriodo::count(), 'Una propuesta nunca llega a planilla sin RR. HH.');
    }

    public function test_propuesta_valida_montos_ciclos_y_alcance(): void
    {
        $ciclo = $this->ciclo();
        foreach (['0', '-10', '10.555', 'abc'] as $monto) {
            $this->como($this->gerente)->postJson('/api/portal/bonos', $this->datosBono($ciclo, ['monto' => $monto]))
                ->assertUnprocessable()->assertJsonValidationErrors('monto');
        }
        $this->como($this->gerente)->postJson('/api/portal/bonos', $this->datosBono($this->ciclo('cerrado', '2025-01-01', '2025-01-31')))
            ->assertUnprocessable()->assertJsonValidationErrors('ciclo_id');
        $this->como($this->gerente)->postJson('/api/portal/bonos', $this->datosBono($ciclo, ['colaborador_id' => $this->otraArea->id]))
            ->assertNotFound();
    }

    public function test_rrhh_valida_la_propuesta_con_el_caso_de_uso_de_remuneraciones(): void
    {
        $ciclo = $this->ciclo();
        $id = $this->como($this->gerente)->postJson('/api/portal/bonos', $this->datosBono($ciclo))->assertCreated()->json('data.id');
        $comision = ConceptoRemuneracion::where('codigo', 'COMISION')->value('id');

        $this->como($this->gerente)->patchJson("/api/propuestas-conceptos/{$id}", ['accion' => 'validar', 'concepto_id' => $comision])->assertForbidden();

        $this->como($this->rrhh())->patchJson("/api/propuestas-conceptos/{$id}", ['accion' => 'validar', 'concepto_id' => $comision])
            ->assertOk()->assertJsonPath('data.estado', 'validado')->assertJsonPath('data.incluido_en_ciclo', true);
        $concepto = ColaboradorConceptoPeriodo::firstOrFail();
        $this->assertSame('350.50', (string) $concepto->monto);
        $this->assertSame($concepto->id, PropuestaConcepto::findOrFail($id)->colaborador_concepto_periodo_id);

        $this->como($this->rrhh())->patchJson("/api/propuestas-conceptos/{$id}", ['accion' => 'rechazar', 'observacion' => 'x'])->assertStatus(409);
    }

    // ------------------------------------------------------------------ Ceses

    private function solicitarCese(array $extra = [], ?array $evidencias = null)
    {
        return $this->como($this->gerente)->post('/api/portal/ceses', array_merge([
            'colaborador_id' => $this->propio->id, 'fecha_cese_propuesta' => $this->hoyNegocio(),
            'motivo' => 'Abandono de trabajo reiterado y documentado.', 'evidencias' => $evidencias ?? [$this->pdf()],
        ], $extra), ['Accept' => 'application/json']);
    }

    public function test_solicitud_de_cese_valida_evidencias_y_evita_duplicados(): void
    {
        $this->solicitarCese([], [])->assertUnprocessable()->assertJsonValidationErrors('evidencias');
        $falso = UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es un pdf');
        $this->solicitarCese([], [$falso])->assertUnprocessable();
        $this->solicitarCese(['fecha_cese_propuesta' => now()->subDays(3)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_cese_propuesta');

        $this->solicitarCese(['estado' => 'aprobada'])->assertCreated()
            ->assertJsonPath('data.estado', 'enviada')->assertJsonPath('data.etapa', 'enviada')
            ->assertJsonMissingPath('data.evidencias.0.ruta');
        $this->solicitarCese()->assertUnprocessable()->assertJsonValidationErrors('colaborador_id');

        $this->assertSame(1, SolicitudCese::withoutGlobalScopes()->count());
        $this->assertSame(0, SolicitudPersonal::withoutGlobalScopes()->count(), 'Nunca se crea un reemplazo automático.');
    }

    public function test_aprobar_no_cesa_y_solo_la_ejecucion_marca_ejecutada(): void
    {
        $id = $this->solicitarCese()->assertCreated()->json('data.id');
        $rrhh = $this->rrhh();

        $this->como($this->gerente)->patchJson("/api/solicitudes-cese/{$id}/decision", ['accion' => 'aprobar'])->assertForbidden();
        $this->como($rrhh)->patchJson("/api/solicitudes-cese/{$id}/decision", ['accion' => 'aprobar'])->assertOk()
            ->assertJsonPath('data.etapa', 'aprobada_pendiente_ejecucion');
        $this->assertTrue((bool) $this->propio->fresh()->activo, 'Aprobar no debe cesar.');

        // Ejecución fallida (fecha futura no admitida por Personas): sigue pendiente.
        $this->como($rrhh)->postJson("/api/solicitudes-cese/{$id}/ejecutar", [
            'fecha_cese' => now()->addDays(10)->toDateString(), 'motivo_cese' => 'Abandono',
        ])->assertUnprocessable();
        $this->assertNull(SolicitudCese::withoutGlobalScopes()->findOrFail($id)->ejecutada_at);
        $this->assertTrue((bool) $this->propio->fresh()->activo);

        $this->como($rrhh)->postJson("/api/solicitudes-cese/{$id}/ejecutar", [
            'fecha_cese' => now()->toDateString(), 'motivo_cese' => 'Abandono',
        ])->assertOk()->assertJsonPath('data.etapa', 'ejecutada');
        $this->assertFalse((bool) $this->propio->fresh()->activo);

        $this->como($rrhh)->postJson("/api/solicitudes-cese/{$id}/ejecutar", [
            'fecha_cese' => now()->toDateString(), 'motivo_cese' => 'Abandono',
        ])->assertStatus(409);

        // Quien solicitó sigue viendo su solicitud ejecutada.
        $this->como($this->gerente)->getJson("/api/portal/ceses/{$id}")->assertOk()->assertJsonPath('data.etapa', 'ejecutada');
    }

    public function test_rechazo_exige_motivo_y_evidencias_son_privadas(): void
    {
        $solicitud = $this->solicitarCese()->assertCreated()->json('data');
        $evidenciaId = $solicitud['evidencias'][0]['id'];

        $this->como($this->rrhh())->patchJson("/api/solicitudes-cese/{$solicitud['id']}/decision", ['accion' => 'rechazar'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo_rechazo');

        $this->como($this->gerente)->get("/api/portal/ceses/{$solicitud['id']}/evidencias/{$evidenciaId}")->assertOk();
        $otroGerente = $this->crearGerente($this->otraArea->area_id);
        $this->como($otroGerente)->getJson("/api/portal/ceses/{$solicitud['id']}/evidencias/{$evidenciaId}")->assertNotFound();
        $this->como($otroGerente)->getJson("/api/portal/ceses/{$solicitud['id']}")->assertNotFound();
    }
}
