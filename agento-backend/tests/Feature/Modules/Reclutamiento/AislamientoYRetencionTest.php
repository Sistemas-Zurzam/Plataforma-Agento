<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\DocumentoCandidato;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class AislamientoYRetencionTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        Storage::fake('local');
    }

    public function test_usuario_de_otra_empresa_no_ve_ni_modifica_datos_ajenos(): void
    {
        $empresaA = $this->crearEmpresa('A');
        $empresaB = $this->crearEmpresa('B');
        $rysA = $this->usuarioCon($empresaA, 'talento_cultura');
        $rysB = $this->usuarioCon($empresaB, 'talento_cultura');
        $proceso = $this->procesoPublicado($rysA, $this->crearArea($empresaA));
        $this->postular($proceso, 'aislado@correo.test')->assertCreated();
        $postulacion = Postulacion::withoutGlobalScopes()->firstOrFail();
        $headersB = $this->cabeceras($rysB);

        $this->withHeaders($headersB)->getJson('/api/seleccion/solicitudes')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($headersB)->getJson('/api/seleccion/procesos')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($headersB)->getJson('/api/seleccion/postulaciones')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeaders($headersB)->getJson('/api/seleccion/banco-talentos')->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($headersB)->getJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}")->assertNotFound();
        $this->withHeaders($headersB)->getJson("/api/seleccion/procesos/{$proceso->id}")->assertNotFound();
        $this->withHeaders($headersB)->getJson("/api/seleccion/postulaciones/{$postulacion->id}")->assertNotFound();
        $this->withHeaders($headersB)->getJson("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertNotFound();
        $this->withHeaders($headersB)->getJson("/api/seleccion/banco-talentos/{$postulacion->candidato_id}")->assertNotFound();
        $this->withHeaders($headersB)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'filtro_cv'])->assertNotFound();
        $this->withHeaders($headersB)->postJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}/cancelar", ['motivo' => 'x'])->assertNotFound();
        $this->withHeaders($headersB)->postJson('/api/seleccion/postulaciones/acciones-masivas', [
            'ids' => [$postulacion->id], 'accion' => 'mover', 'etapa' => 'filtro_cv',
        ])->assertOk()->assertJsonPath('data.0.ok', false);

        $this->assertSame('postulacion', $postulacion->fresh()->etapa);
        $this->withHeaders($this->cabeceras($rysA))->getJson("/api/seleccion/postulaciones/{$postulacion->id}/cv")->assertOk();
    }

    public function test_empresa_id_enviado_por_el_frontend_se_ignora(): void
    {
        $empresaA = $this->crearEmpresa('A');
        $empresaB = $this->crearEmpresa('B');
        $areaA = $this->crearArea($empresaA);
        $rysA = $this->usuarioCon($empresaA, 'talento_cultura');

        $solicitud = $this->withHeaders($this->cabeceras($rysA))
            ->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($areaA, ['empresa_id' => $empresaB->id]))
            ->assertCreated()->json('data');

        $this->assertDatabaseHas('seleccion_solicitudes', ['id' => $solicitud['id'], 'empresa_id' => $empresaA->id]);

        // Un área de otra empresa tampoco es aceptada.
        $areaB = $this->crearArea($empresaB);
        $this->withHeaders($this->cabeceras($rysA))->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($areaB))
            ->assertStatus(422)->assertJsonValidationErrors('area_id');
    }

    public function test_candidato_solo_ve_sus_postulaciones(): void
    {
        $empresa = $this->crearEmpresa();
        $proceso = $this->procesoPublicado($this->usuarioCon($empresa, 'talento_cultura'), $this->crearArea($empresa));
        $this->postular($proceso, 'uno@correo.test')->assertCreated();
        $this->postular($proceso, 'dos@correo.test')->assertCreated();
        $ajena = Postulacion::withoutGlobalScopes()->whereHas('candidato', fn ($q) => $q->withoutGlobalScopes()->where('email', 'dos@correo.test'))->firstOrFail();

        $token = $this->tokenCandidato('uno@correo.test');
        $this->withHeaders(['X-Candidato-Token' => $token])->getJson('/api/publico/candidatos/postulaciones')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders(['X-Candidato-Token' => $token])->postJson("/api/publico/candidatos/postulaciones/{$ajena->id}/retiro")->assertNotFound();
        $this->assertSame('activa', $ajena->fresh()->estado);
    }

    public function test_retencion_es_ejecucion_de_prueba_por_defecto_y_no_toca_personas(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 2);
        $this->postular($proceso, 'viejo@correo.test')->assertCreated();
        $vieja = Postulacion::withoutGlobalScopes()->firstOrFail();
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$vieja->id}/descartar", [
            'causa' => 'bajo_puntaje', 'motivo' => 'No alcanzó el puntaje',
        ])->assertOk();

        $contratada = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$contratada->id}/contratar", $this->datosContratacion($this->catalogosContratacion($empresa)))->assertCreated();
        $colaboradorId = $contratada->fresh()->colaborador_id;

        Candidato::withoutGlobalScopes()->update(['ultima_postulacion_at' => now()->subMonths(25)]);
        $documento = DocumentoCandidato::withoutGlobalScopes()->firstOrFail();
        Storage::disk('local')->assertExists($documento->ruta);

        // Sin bandera de configuración: no cambia nada aunque se pida ejecutar.
        $this->artisan('reclutamiento:vencer-candidatos --ejecutar')->assertSuccessful();
        $this->assertNull(Candidato::withoutGlobalScopes()->find($vieja->candidato_id)->anonimizado_at);

        config(['reclutamiento.retencion.eliminacion_habilitada' => true]);
        $this->artisan('reclutamiento:vencer-candidatos --ejecutar')->assertSuccessful();

        $anonimizado = Candidato::withoutGlobalScopes()->find($vieja->candidato_id);
        $this->assertNotNull($anonimizado->anonimizado_at);
        $this->assertNull($anonimizado->numero_documento);
        Storage::disk('local')->assertMissing($documento->ruta);
        $this->assertNull($vieja->fresh()->perfil_snapshot);

        // El contratado (excepción configurada) y su expediente laboral quedan intactos.
        $this->assertNull(Candidato::withoutGlobalScopes()->find($contratada->candidato_id)->anonimizado_at);
        $this->assertTrue(Colaborador::findOrFail($colaboradorId)->activo);
    }
}
