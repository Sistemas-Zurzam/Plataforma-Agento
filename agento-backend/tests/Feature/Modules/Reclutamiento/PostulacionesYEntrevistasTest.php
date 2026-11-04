<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Reclutamiento\Models\PostulacionMovimiento;
use App\Modules\Reclutamiento\Models\PuntajeFinal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class PostulacionesYEntrevistasTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    public function test_transiciones_invalidas_son_rechazadas_en_backend(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $headers = $this->cabeceras($rys);

        $descartada = $this->postulacionEn($proceso, 'filtro_cv', 'descartada');
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$descartada->id}/mover", ['etapa' => 'psicometrica'])->assertStatus(422);

        $activa = $this->postulacionEn($proceso, 'filtro_cv');
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$activa->id}/seleccionar")->assertStatus(422);
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$activa->id}/mover", ['etapa' => 'postulacion'])->assertStatus(422);
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$activa->id}/contratar", $this->datosContratacion($this->catalogosContratacion($empresa)))
            ->assertStatus(422);
    }

    public function test_movimientos_registran_actor_motivo_y_descarte_separa_mensaje_publico(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $postulacion = $this->postulacionEn($proceso);
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'entrevista_virtual'])->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'filtro_cv', 'motivo' => 'Revisar CV nuevamente'])->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/descartar", [
            'causa' => 'expectativa_salarial', 'motivo' => 'Pide 3000, fuera de rango',
        ])->assertOk()->assertJsonPath('data.estado', 'descartada');

        $movimientos = PostulacionMovimiento::where('postulacion_id', $postulacion->id)->orderBy('id')->get();
        $this->assertCount(3, $movimientos);
        $this->assertSame($rys->id, $movimientos[1]->actor_user_id);
        $this->assertSame('Revisar CV nuevamente', $movimientos[1]->motivo);

        $postulacion->refresh();
        $this->assertSame('Pide 3000, fuera de rango', $postulacion->motivo_interno);
        $this->assertStringNotContainsString('3000', $postulacion->mensaje_publico);
    }

    public function test_accion_masiva_devuelve_resultado_individual(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $ok = $this->postulacionEn($proceso);
        $ya = $this->postulacionEn($proceso, 'filtro_cv');

        $resultado = $this->withHeaders($this->cabeceras($rys))->postJson('/api/seleccion/postulaciones/acciones-masivas', [
            'ids' => [$ok->id, $ya->id, 999999], 'accion' => 'mover', 'etapa' => 'filtro_cv',
        ])->assertOk()->json('data');

        $this->assertTrue($resultado[0]['ok']);
        $this->assertFalse($resultado[1]['ok']);
        $this->assertFalse($resultado[2]['ok']);
    }

    public function test_entrevista_normaliza_conserva_autor_y_puntaje_final_queda_provisional(): void
    {
        $empresa = $this->crearEmpresa();
        $area = $this->crearArea($empresa);
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $gerente = $this->usuarioCon($empresa, 'gerencia', $area);
        $proceso = $this->procesoPublicado($rys, $area);
        $postulacion = $this->postulacionEn($proceso, 'entrevista_presencial');

        $entrevista = $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/entrevistas", [
            'tipo' => 'gerencia', 'evaluador_user_id' => $gerente->id,
        ])->assertCreated()->json('data');

        $evaluacion = [
            'calificaciones' => [['competencia' => 'Montacargas', 'calificacion' => 4], ['competencia' => 'Inventarios', 'calificacion' => 2]],
            'fortalezas' => 'Experiencia', 'riesgos' => 'Disponibilidad', 'recomendacion' => 'avanzar',
        ];
        // El gerente evaluador asignado puede evaluar aunque no gestione evaluaciones.
        $this->withHeaders($this->cabeceras($gerente))->postJson("/api/seleccion/entrevistas/{$entrevista['id']}/evaluaciones", $evaluacion)
            ->assertCreated()->assertJsonPath('data.puntaje', 50);
        $this->withHeaders($this->cabeceras($gerente))->postJson("/api/seleccion/entrevistas/{$entrevista['id']}/evaluaciones", $evaluacion)->assertStatus(422);

        $final = PuntajeFinal::where('postulacion_id', $postulacion->id)->latest('id')->first();
        $this->assertTrue($final->provisional);   // ATS y psicometría siguen pendientes
        $this->assertSame(50.0, $final->puntaje); // no se convierten en 0

        $filasAntes = PuntajeFinal::where('postulacion_id', $postulacion->id)->count();
        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/no-aplica", [
            'componente' => 'psicometria', 'no_aplica' => true, 'motivo' => 'Puesto operativo sin batería aprobada',
        ])->assertOk();
        $this->assertSame($filasAntes + 1, PuntajeFinal::where('postulacion_id', $postulacion->id)->count(), 'el historial de puntajes se conserva');
        $this->assertSame(50.0, PuntajeFinal::where('postulacion_id', $postulacion->id)->latest('id')->first()->puntaje);
    }

    public function test_evaluacion_ats_manual_con_excluyente_incumplido(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $postulacion = $this->postulacionEn($proceso, 'filtro_cv');
        $requisito = $proceso->perfilVigente()->first()->requisitos()->first();

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/postulaciones/{$postulacion->id}/evaluacion-ats", [
            'requisitos' => [$requisito->id => 'no_cumple'],
            'competencias' => ['Montacargas' => 3, 'Inventarios' => 2],
        ])->assertOk()->assertJsonPath('data.ats.puntaje', 0)->assertJsonPath('data.ats.resultado', 'no_apto')
            ->assertJsonPath('data.estado', 'activa'); // el puntaje recomienda; no descarta solo
    }
}
