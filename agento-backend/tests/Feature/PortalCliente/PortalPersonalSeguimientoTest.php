<?php

namespace Tests\Feature\PortalCliente;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Models\Plaza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Seguimiento de solicitudes de personal en el Portal de Empresa: agregado
 * por etapa y por plazas del proceso real, restringido a las áreas del
 * gerente y sin datos privados de candidatos.
 */
class PortalPersonalSeguimientoTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal_cliente.enabled' => true]);
        $this->prepararSeleccion();
    }

    public function test_seguimiento_agregado_por_plazas_sin_datos_de_candidatos(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $otraArea = $this->crearArea($empresa, 'Ventas');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $gerente = $this->usuarioCon($empresa, 'gerencia', $area);

        $proceso = $this->procesoPublicado($rys, $area, 3);
        $this->procesoPublicado($rys, $otraArea, 1);
        $this->postulacionEn($proceso, 'entrevista_virtual', FlujoPostulacion::ACTIVA, ['email' => 'privado@candidato.test']);
        $this->postulacionEn($proceso, 'filtro_cv');
        $this->postulacionEn($proceso, 'seleccion', FlujoPostulacion::DESCARTADA);
        Plaza::withoutGlobalScopes()->where('proceso_id', $proceso->id)->orderBy('numero')->first()
            ->update(['estado' => Plaza::CUBIERTA, 'cubierta_at' => now()]);

        $respuesta = $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/personal/solicitudes')->assertOk();

        $this->assertCount(1, $respuesta->json('data'), 'Solo solicitudes de las áreas del gerente.');
        $seguimiento = $respuesta->json('data.0.seguimiento');
        $this->assertSame(3, $seguimiento['plazas_vigentes']);
        $this->assertSame(1, $seguimiento['plazas_cubiertas']);
        $this->assertFalse($seguimiento['cubierto'], 'Una plaza cubierta de tres no cubre la solicitud.');
        $this->assertSame(1, $seguimiento['etapas']['entrevista_virtual']);
        $this->assertSame(1, $seguimiento['etapas']['evaluaciones']);
        $this->assertSame(0, $seguimiento['etapas']['seleccion'], 'Las descartadas no cuentan.');

        $cuerpo = $respuesta->getContent();
        foreach (['privado@candidato.test', 'puntaje', 'motivo_interno', 'salario'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $cuerpo);
        }
    }

    public function test_inicio_resume_solicitudes_devueltas_y_avance(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $gerente = $this->usuarioCon($empresa, 'gerencia', $area);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');

        $solicitud = $this->withHeaders($this->cabeceras($gerente))
            ->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, ['enviar' => true]))->assertCreated()->json('data');
        $this->withHeaders($this->cabeceras($rys))
            ->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'devolver', 'motivo' => 'Completa la justificación'])
            ->assertOk();

        $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/inicio')->assertOk()
            ->assertJsonPath('data.personal.devueltas', 1);
        $this->withHeaders($this->cabeceras($gerente))->getJson('/api/portal/personal/solicitudes?estado=devuelta')->assertOk()
            ->assertJsonPath('data.0.respuesta_rrhh', 'Completa la justificación')
            ->assertJsonPath('data.0.siguiente_responsable', 'solicitante')
            ->assertJsonPath('data.0.editable', true);
    }
}
