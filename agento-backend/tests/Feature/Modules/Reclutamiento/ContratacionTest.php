<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorPeriodoPrueba;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class ContratacionTest extends TestCase
{
    use CreaColaboradorDePrueba, CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    public function test_contratar_crea_colaborador_cubre_plaza_y_deja_tareas(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1);
        $postulacion = $this->postulacionEn($proceso, 'seleccion', 'seleccionada', ['numero_documento' => '45678912', 'email' => 'nuevo@correo.test']);
        $datos = $this->datosContratacion($this->catalogosContratacion($empresa), [
            'fecha_fin_prueba' => now()->addWeek()->addMonths(3)->toDateString(),
        ]);

        $respuesta = $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", $datos)
            ->assertCreated()
            ->assertJsonPath('data.ficha_incompleta', true)
            ->assertJsonPath('data.onboarding.estado', 'pendiente_integracion')
            ->assertJsonPath('data.proceso.estado', ProcesoSeleccion::CUBIERTO);

        $colaborador = Colaborador::findOrFail($respuesta->json('data.colaborador.id'));
        $this->assertSame('45678912', $colaborador->numero_documento);
        $this->assertSame('nuevo@correo.test', $colaborador->email);
        $this->assertSame($proceso->cargo, $colaborador->cargo);
        $this->assertSame($proceso->area_id, $colaborador->area_id);
        $this->assertSame($empresa->id, $colaborador->empresa_id);
        $this->assertNotNull($colaborador->remuneracionVigente);

        $plaza = Plaza::withoutGlobalScopes()->where('proceso_id', $proceso->id)->first();
        $this->assertSame(Plaza::CUBIERTA, $plaza->estado);
        $this->assertSame($postulacion->id, $plaza->postulacion_id);
        $this->assertSame($colaborador->id, $plaza->colaborador_id);
        $this->assertTrue(Tarea::withoutGlobalScopes()->where('tipo', Tarea::COMPLETAR_FICHA)->where('colaborador_id', $colaborador->id)->exists());
        $this->assertTrue(Tarea::withoutGlobalScopes()->where('tipo', Tarea::ONBOARDING)->exists());
        $this->assertSame('en_curso', ColaboradorPeriodoPrueba::where('colaborador_id', $colaborador->id)->value('resultado'));
    }

    public function test_doble_clic_no_duplica_el_alta(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 2);
        $postulacion = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $datos = $this->datosContratacion($this->catalogosContratacion($empresa));
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", $datos)->assertCreated();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", $datos)->assertStatus(422);

        $this->assertSame(1, Colaborador::where('empresa_id', $empresa->id)->count());
        $this->assertSame(1, Plaza::withoutGlobalScopes()->where('estado', Plaza::CUBIERTA)->count());
    }

    public function test_la_ultima_plaza_no_se_cubre_dos_veces(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1);
        $primera = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $segunda = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $catalogos = $this->catalogosContratacion($empresa);
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$primera->id}/contratar", $this->datosContratacion($catalogos))->assertCreated();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$segunda->id}/contratar", $this->datosContratacion($catalogos))->assertStatus(422);

        $this->assertSame('seleccionada', $segunda->fresh()->estado);
        $this->assertSame(1, Colaborador::where('empresa_id', $empresa->id)->count());

        // Además de los bloqueos, la BD impide que una postulación ocupe dos plazas.
        $plaza = Plaza::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'proceso_id' => $proceso->id, 'numero' => 2, 'estado' => 'disponible']);
        $this->expectException(QueryException::class);
        $plaza->update(['postulacion_id' => $primera->id]);
    }

    public function test_dni_de_colaborador_activo_bloquea_la_contratacion(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1);
        $activo = $this->crearColaborador($empresa, ['numero_documento' => '40404040']);
        $postulacion = $this->postulacionEn($proceso, 'seleccion', 'seleccionada', ['numero_documento' => '40404040']);

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", $this->datosContratacion($this->catalogosContratacion($empresa)))
            ->assertStatus(422)->assertJsonValidationErrors('numero_documento');

        $this->assertSame('seleccionada', $postulacion->fresh()->estado);
        $this->assertSame(1, Colaborador::where('numero_documento', '40404040')->count());
        $this->assertTrue($activo->fresh()->activo);
    }

    public function test_cesado_requiere_resolucion_explicita_y_no_se_duplica(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1);
        $cesado = $this->crearColaborador($empresa, ['numero_documento' => '50505050', 'activo' => false, 'fecha_cese' => now()->subYear()]);
        $postulacion = $this->postulacionEn($proceso, 'seleccion', 'seleccionada', ['numero_documento' => '50505050']);
        $datos = $this->datosContratacion($this->catalogosContratacion($empresa));
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", $datos)
            ->assertStatus(409)->assertJsonPath('requiere_resolucion', 'cesado');
        $this->assertSame('seleccionada', $postulacion->fresh()->estado);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/contratar", [...$datos, 'resolucion_cesado' => 'vincular_reingreso'])
            ->assertCreated()->assertJsonPath('data.reingreso', true);

        $this->assertSame(1, Colaborador::where('numero_documento', '50505050')->count());
        $this->assertSame($cesado->id, $postulacion->fresh()->colaborador_id);
        $this->assertFalse($cesado->fresh()->activo, 'la ficha del cesado no se modifica desde Selección');
        $this->assertTrue(Tarea::withoutGlobalScopes()->where('tipo', Tarea::REINGRESO)->exists());
    }

    public function test_cancelar_no_revierte_contrataciones(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 2);
        $contratada = $this->postulacionEn($proceso, 'seleccion', 'seleccionada');
        $enCurso = $this->postulacionEn($proceso, 'entrevista_virtual');
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$contratada->id}/contratar", $this->datosContratacion($this->catalogosContratacion($empresa)))->assertCreated();
        $this->withHeaders($headers)->postJson("/api/seleccion/solicitudes/{$proceso->solicitud_id}/cancelar", ['motivo' => 'Cambio de prioridades'])
            ->assertOk()->assertJsonPath('impacto.postulaciones_activas', 1)->assertJsonPath('impacto.plazas_cubiertas', 1);

        $this->assertSame('contratada', $contratada->fresh()->estado);
        $this->assertTrue(Colaborador::findOrFail($contratada->fresh()->colaborador_id)->activo);
        $this->assertSame('finalizada_por_cancelacion', $enCurso->fresh()->estado);
        $this->assertSame('proceso_cancelado', $enCurso->fresh()->causa_descarte);
        $this->assertSame(
            ['cancelada', 'cubierta'],
            Plaza::withoutGlobalScopes()->where('proceso_id', $proceso->id)->orderBy('estado')->pluck('estado')->all(),
        );
        $this->assertSame(ProcesoSeleccion::CANCELADO, $proceso->fresh()->estado);
        $this->assertFalse((bool) $proceso->fresh()->publicacion_externa);
        $this->assertSame(2, Postulacion::withoutGlobalScopes()->where('proceso_id', $proceso->id)->count());
    }
}
