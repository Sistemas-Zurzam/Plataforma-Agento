<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Servicios contratados por empresa (planilla / reclutamiento), portal por
 * servicio, colocación sin planilla y condiciones comerciales.
 */
class ServiciosEmpresaTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        config(['portal_cliente.enabled' => true]);
    }

    public function test_seleccion_exige_el_servicio_de_reclutamiento(): void
    {
        $empresa = $this->crearEmpresa('Solo planilla', ['servicio_reclutamiento' => false]);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $this->withHeaders($this->cabeceras($rys))->getJson('/api/seleccion/solicitudes')
            ->assertForbidden()->assertJsonPath('servicio_no_contratado', 'reclutamiento');
    }

    public function test_portal_publico_oculta_procesos_de_empresas_sin_reclutamiento(): void
    {
        $empresa = $this->crearEmpresa();
        $proceso = $this->procesoPublicado($this->usuarioCon($empresa, 'talento_cultura'), $this->crearArea($empresa));
        $this->getJson('/api/publico/empleos')->assertOk()->assertJsonCount(1, 'data');

        $empresa->update(['servicio_reclutamiento' => false]);

        $this->getJson('/api/publico/empleos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/publico/empleos/{$proceso->codigo}")->assertNotFound();
    }

    public function test_portal_muestra_solo_las_secciones_de_los_servicios_contratados(): void
    {
        $soloReclutamiento = $this->crearEmpresa('Solo reclutamiento', ['servicio_planilla' => false]);
        $area = $this->crearArea($soloReclutamiento);
        $gerente = $this->usuarioCon($soloReclutamiento, 'gerencia', $area);
        $headers = $this->cabeceras($gerente);

        $contexto = $this->withHeaders($headers)->getJson('/api/portal/contexto')->assertOk();
        $contexto->assertJsonPath('data.servicios', ['planilla' => false, 'reclutamiento' => true])
            ->assertJsonPath('data.capacidades.equipo', false)
            ->assertJsonPath('data.capacidades.ceses', false)
            ->assertJsonPath('data.capacidades.personal', true)
            ->assertJsonPath('data.capacidades.personal_crear', true);

        $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/asistencia/colaboradores')
            ->assertForbidden()->assertJsonPath('servicio_no_contratado', 'planilla');
        $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/personal/solicitudes')->assertOk();

        $soloPlanilla = $this->crearEmpresa('Solo planilla', ['servicio_reclutamiento' => false]);
        $otroGerente = $this->usuarioCon($soloPlanilla, 'gerencia', $this->crearArea($soloPlanilla));
        $this->withHeaders($this->cabeceras($otroGerente))->getJson('/api/portal/contexto')
            ->assertOk()->assertJsonPath('data.capacidades.personal', false);
        $this->withHeaders($this->cabeceras($otroGerente))->getJson('/api/portal/personal/solicitudes')
            ->assertForbidden()->assertJsonPath('servicio_no_contratado', 'reclutamiento');
    }

    public function test_cliente_consulta_el_avance_de_toda_su_empresa_sin_poder_gestionar(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $this->procesoPublicado($rys, $area, 2);
        $cliente = $this->usuarioCon($empresa, 'cliente_empresa');

        $otra = $this->crearEmpresa('Otra');
        $this->procesoPublicado($this->usuarioCon($otra, 'talento_cultura'), $this->crearArea($otra), 1);

        $listado = $this->withHeaders($this->cabeceras($cliente))->getJson('/api/portal/personal/solicitudes')->assertOk();
        $listado->assertJsonCount(1, 'data')->assertJsonPath('data.0.seguimiento.plazas_vigentes', 2)
            ->assertJsonPath('data.0.editable', false);
        $this->assertStringNotContainsString('cobro', $listado->getContent());

        $this->withHeaders($this->cabeceras($cliente))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area))->assertForbidden();
        $this->withHeaders($this->cabeceras($cliente))->getJson('/api/seleccion/procesos')->assertForbidden();
    }

    public function test_colocacion_sin_planilla_cubre_la_plaza_sin_crear_colaborador(): void
    {
        $empresa = $this->crearEmpresa('Solo reclutamiento', ['servicio_planilla' => false]);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1);
        $postulacion = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $ingreso = now()->addWeek()->toDateString();

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", [
            'fecha_ingreso' => $ingreso, 'salario' => 1800,
        ])->assertCreated()->assertJsonPath('data.colocacion', true)->assertJsonPath('data.colaborador', null)
            ->assertJsonPath('data.proceso.estado', 'cubierto');

        $plaza = Plaza::withoutGlobalScopes()->where('proceso_id', $proceso->id)->firstOrFail();
        $this->assertSame(Plaza::CUBIERTA, $plaza->estado);
        $this->assertSame($postulacion->id, $plaza->postulacion_id);
        $this->assertNull($plaza->colaborador_id);
        $this->assertSame($ingreso, $plaza->fecha_ingreso->toDateString());
        $this->assertSame('contratada', $postulacion->fresh()->estado);
        $this->assertSame(0, Colaborador::where('empresa_id', $empresa->id)->count());
        $this->assertSame(0, Tarea::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }

    public function test_condiciones_comerciales_solo_las_registra_y_ve_quien_decide(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $gerente = $this->usuarioCon($empresa, 'gerencia', $area);
        $solicitud = $this->withHeaders($this->cabeceras($gerente))
            ->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, ['enviar' => true]))->assertCreated()->json('data');

        $condiciones = ['cobro_modalidad' => 'porcentaje_salario', 'cobro_monto' => 80, 'garantia_dias' => 60, 'condiciones_notas' => 'Reposición sin costo dentro de la garantía'];
        $this->withHeaders($this->cabeceras($gerente))->putJson("/api/seleccion/solicitudes/{$solicitud['id']}/condiciones", $condiciones)->assertForbidden();
        $this->withHeaders($this->cabeceras($rys))->putJson("/api/seleccion/solicitudes/{$solicitud['id']}/condiciones", [...$condiciones, 'cobro_monto' => 150])
            ->assertStatus(422)->assertJsonValidationErrors('cobro_monto');
        $this->withHeaders($this->cabeceras($rys))->putJson("/api/seleccion/solicitudes/{$solicitud['id']}/condiciones", $condiciones)
            ->assertOk()->assertJsonPath('data.condiciones.cobro_modalidad', 'porcentaje_salario')->assertJsonPath('data.condiciones.garantia_dias', 60);

        $this->assertSame('80.00', SolicitudPersonal::withoutGlobalScopes()->find($solicitud['id'])->cobro_monto);
        $this->withHeaders($this->cabeceras($gerente))->getJson("/api/seleccion/solicitudes/{$solicitud['id']}")
            ->assertOk()->assertJsonMissingPath('data.condiciones');
        $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/personal/solicitudes')
            ->assertOk()->assertJsonMissingPath('data.0.condiciones');
    }
}
