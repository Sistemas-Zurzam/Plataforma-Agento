<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Reclutamiento\Models\Actividad;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class SolicitudesYProcesosTest extends TestCase
{
    use CreaColaboradorDePrueba, CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    public function test_cerrar_el_proceso_finaliza_las_postulaciones_y_el_candidato_lo_ve(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $this->postular($proceso, 'cierre@correo.test')->assertCreated();
        $postulacion = Postulacion::withoutGlobalScopes()->where('proceso_id', $proceso->id)->firstOrFail();

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/procesos/{$proceso->id}/estado", [
            'accion' => 'cerrar', 'motivo' => 'Se cubrió por otro canal',
        ])->assertOk();

        $this->assertSame('finalizada_por_cancelacion', $postulacion->fresh()->estado);
        $this->assertSame('proceso_cerrado', $postulacion->fresh()->causa_descarte);

        $vista = $this->withHeaders(['X-Candidato-Token' => $this->tokenCandidato('cierre@correo.test')])
            ->getJson('/api/publico/candidatos/postulaciones')->assertOk()->json('data.0');
        $this->assertFalse($vista['vigente']);
        $this->assertSame('Proceso finalizado', $vista['estado_publico']);
        $this->assertArrayNotHasKey('causa_descarte', $vista);
    }

    public function test_aceptar_genera_un_proceso_con_n_plazas_y_registra_actividad(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $proceso = $this->procesoPublicado($rys, $area, 10);

        $this->assertSame(10, Plaza::withoutGlobalScopes()->where('proceso_id', $proceso->id)->count());
        $this->assertSame(ProcesoSeleccion::ABIERTO, $proceso->estado);
        $this->assertTrue(Actividad::withoutGlobalScopes()->where('entidad_tipo', 'SolicitudPersonal')->where('accion', 'solicitud_aceptada')->exists());

        $detalle = $this->withHeaders($this->cabeceras($rys))->getJson("/api/seleccion/procesos/{$proceso->id}")->assertOk();
        $detalle->assertJsonPath('data.plazas_total', 10)->assertJsonPath('data.plazas_cubiertas', 0);
    }

    public function test_aceptar_dos_veces_no_duplica_proceso_ni_plazas(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $headers = $this->cabeceras($rys);

        $solicitud = $this->withHeaders($headers)->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, ['plazas' => 3, 'enviar' => true]))
            ->assertCreated()->json('data');
        $this->withHeaders($headers)->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'aceptar'])->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'aceptar'])->assertStatus(422);

        $this->assertSame(1, ProcesoSeleccion::withoutGlobalScopes()->where('solicitud_id', $solicitud['id'])->count());
        $this->assertSame(3, Plaza::withoutGlobalScopes()->count());
    }

    public function test_devolver_y_rechazar_exigen_motivo_y_el_solicitante_edita_la_devuelta(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $ger = $this->usuarioCon($empresa, 'gerencia', $area);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $solicitud = $this->withHeaders($this->cabeceras($ger))
            ->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, ['enviar' => true]))->assertCreated()->json('data');

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'devolver'])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'devolver', 'motivo' => 'Precisar rango salarial'])
            ->assertOk()->assertJsonPath('data.estado', 'devuelta');

        $this->withHeaders($this->cabeceras($ger))->putJson("/api/seleccion/solicitudes/{$solicitud['id']}", $this->datosSolicitud($area, ['salario_max' => 1600, 'enviar' => true]))
            ->assertOk()->assertJsonPath('data.estado', 'enviada');
    }

    public function test_validaciones_de_solicitud(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $this->withHeaders($this->cabeceras($rys))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, [
            'plazas' => 51, 'descripcion' => 'corta', 'salario_max' => 100,
        ]))->assertStatus(422)->assertJsonValidationErrors(['plazas', 'descripcion', 'salario_max']);
    }

    public function test_gerencia_solicita_para_cualquier_area_y_las_referencias_omiten_gerencia(): void
    {
        $empresa = $this->crearEmpresa();
        $gerenciaArea = Area::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'nombre' => 'GERENCIA']);
        $ventas = Area::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'nombre' => 'Ventas']);
        $finanzas = Area::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'nombre' => 'Finanzas']);
        $ger = $this->usuarioCon($empresa, 'gerencia', $gerenciaArea);
        $jefe = $this->usuarioCon($empresa, 'jefe_area', $ventas);
        $this->crearColaborador($empresa, ['area_id' => $ventas->id, 'cargo' => 'Vendedor de campo']);

        $referencias = $this->withHeaders($this->cabeceras($ger))->getJson('/api/seleccion/solicitudes/referencias')->assertOk()->json('data');
        $nombres = collect($referencias['areas'])->pluck('nombre');
        $this->assertTrue($nombres->contains('Ventas') && $nombres->contains('Finanzas'));
        $this->assertFalse($nombres->contains('GERENCIA'));
        $this->assertContains(['nombre' => 'Vendedor de campo', 'area_id' => $ventas->id], $referencias['cargos']);

        $this->withHeaders($this->cabeceras($ger))->postJson('/api/seleccion/solicitudes', [
            ...$this->datosSolicitud($finanzas), 'requisitos' => "Excel avanzado\nPortafolio", 'horario' => 'Lunes a viernes de 9 a 6', 'habilidades' => [],
        ])->assertCreated()->assertJsonPath('data.requisitos', "Excel avanzado\nPortafolio")->assertJsonPath('data.horario', 'Lunes a viernes de 9 a 6');

        $this->withHeaders($this->cabeceras($jefe))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($finanzas))->assertForbidden();
        $referenciasJefe = $this->withHeaders($this->cabeceras($jefe))->getJson('/api/seleccion/solicitudes/referencias')->assertOk()->json('data');
        $this->assertSame(['Ventas'], collect($referenciasJefe['areas'])->pluck('nombre')->all());
    }

    public function test_jefe_area_solo_crea_y_ve_solicitudes_de_sus_areas(): void
    {
        $empresa = $this->crearEmpresa();
        $areaPropia = $this->crearArea($empresa, 'Ventas');
        $areaAjena = $this->crearArea($empresa, 'Finanzas');
        $ger = $this->usuarioCon($empresa, 'jefe_area', $areaPropia);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $this->withHeaders($this->cabeceras($ger))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($areaAjena))->assertForbidden();
        $this->withHeaders($this->cabeceras($ger))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($areaPropia))->assertCreated();
        $ajena = $this->withHeaders($this->cabeceras($rys))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($areaAjena))->assertCreated()->json('data');

        $listado = $this->withHeaders($this->cabeceras($ger))->getJson('/api/seleccion/solicitudes')->assertOk()->json('data');
        $this->assertCount(1, $listado);
        $this->assertSame($areaPropia->id, $listado[0]['area_id']);
        $this->withHeaders($this->cabeceras($ger))->getJson("/api/seleccion/solicitudes/{$ajena['id']}")->assertForbidden();
        $this->withHeaders($this->cabeceras($ger))->postJson("/api/seleccion/solicitudes/{$ajena['id']}/decision", ['accion' => 'aceptar'])->assertForbidden();
    }

    public function test_ger_responsable_de_area_tambien_la_gestiona(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa, 'Ventas');
        $ger = $this->usuarioCon($empresa, 'jefe_area');
        $area->update(['responsable_user_id' => $ger->id]);

        $this->withHeaders($this->cabeceras($ger))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area))->assertCreated();
    }

    public function test_ger_no_accede_a_postulaciones_ni_cv_pero_si_al_progreso(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $ger = $this->usuarioCon($empresa, 'gerencia', $area);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $area);
        $postulacion = $this->postulacionEn($proceso);

        $this->withHeaders($this->cabeceras($ger))->getJson("/api/seleccion/procesos/{$proceso->id}")->assertOk()
            ->assertJsonPath('candidatos_por_etapa.postulacion', 1);
        $this->withHeaders($this->cabeceras($ger))->getJson("/api/seleccion/postulaciones/{$postulacion->id}")->assertForbidden();
        $this->withHeaders($this->cabeceras($ger))->getJson("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertForbidden();
        $this->withHeaders($this->cabeceras($ger))->getJson("/api/seleccion/procesos/{$proceso->id}/tablero")->assertForbidden();
    }

    public function test_pausa_guarda_motivo_interno_separado_del_mensaje_publico(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $area);

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/procesos/{$proceso->id}/estado", [
            'accion' => 'pausar', 'motivo' => 'Presupuesto congelado por gerencia', 'mensaje_publico' => 'Proceso en pausa temporal.',
        ])->assertOk()->assertJsonPath('data.estado', 'en_pausa');

        $publico = $this->getJson("/api/publico/empleos/{$proceso->codigo}")->assertOk();
        $publico->assertJsonPath('data.acepta_postulaciones', false)->assertJsonPath('data.mensaje_publico', 'Proceso en pausa temporal.');
        $this->assertStringNotContainsString('Presupuesto congelado', $publico->getContent());

        $this->postular($proceso, 'pausa@correo.test')->assertStatus(422);
    }

    public function test_cancelar_solicitud_aceptada_exige_motivo_e_informa_impacto(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $area);
        $this->postulacionEn($proceso);
        $this->postulacionEn($proceso, 'filtro_cv');

        $this->withHeaders($this->cabeceras($rys))->getJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}/impacto-cancelacion")
            ->assertOk()->assertJsonPath('data.postulaciones_activas', 2);
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}/cancelar", [])->assertStatus(422);
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}/cancelar", ['motivo' => 'Se reestructuró el área'])
            ->assertOk()->assertJsonPath('data.estado', SolicitudPersonal::CANCELADA)->assertJsonPath('impacto.postulaciones_activas', 2);

        $this->getJson('/api/publico/empleos')->assertOk()->assertJsonCount(0, 'data');
    }
}
