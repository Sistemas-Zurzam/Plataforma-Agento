<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Reclutamiento\Models\IntentoRespuesta;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\PruebaIntento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class PruebasPsicometricasTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    /**
     * @return array{rys: User, postulacion: Postulacion, token: string, prueba: array}
     */
    private function asignarPrueba(int $minutos = 10): array
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $postulacion = $this->postulacionEn($proceso, 'psicometrica', 'activa', ['email' => 'prueba@correo.test']);
        $headers = $this->cabeceras($rys);

        $prueba = $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [
            'nombre' => 'Atención al detalle',
            'tipo' => 'conocimientos',
            'tiempo_limite_minutos' => $minutos,
            'dimensiones' => [['clave' => 'd1', 'nombre' => 'Precisión', 'peso' => 1]],
            'preguntas' => [
                ['dimension' => 'd1', 'enunciado' => '¿2 + 2?', 'opciones' => [['texto' => '4', 'valor' => 1], ['texto' => '5', 'valor' => 0]]],
                ['dimension' => 'd1', 'enunciado' => '¿3 + 3?', 'opciones' => [['texto' => '6', 'valor' => 1], ['texto' => '7', 'valor' => 0]]],
            ],
        ])->assertCreated()->json('data');
        $this->assertFalse($prueba['validada'], 'una prueba nueva nunca nace validada');

        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/publicar")->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/pruebas/{$prueba['id']}")->assertCreated();

        $cuerpo = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('#/empleos/prueba/([A-Za-z0-9]+)#', $cuerpo, $m);

        return ['rys' => $rys, 'postulacion' => $postulacion, 'token' => $m[1], 'prueba' => $prueba];
    }

    public function test_endpoints_publicos_no_exponen_claves_ni_resultados(): void
    {
        ['token' => $token] = $this->asignarPrueba();

        $inicio = $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk();
        $this->assertStringNotContainsString('valor', $inicio->getContent());
        $this->assertStringNotContainsString('dimension', $inicio->getContent());

        $pregunta = $inicio->json('data.preguntas.0');
        $this->postJson("/api/publico/pruebas/{$token}/respuestas", ['pregunta_id' => $pregunta['id'], 'opcion_id' => $pregunta['opciones'][0]['id']])->assertOk();
        $fin = $this->postJson("/api/publico/pruebas/{$token}/finalizar")->assertOk();
        $this->assertStringNotContainsString('puntaje', $fin->getContent());
        $this->assertStringNotContainsString('puntaje', $this->getJson("/api/publico/pruebas/{$token}")->getContent());
    }

    public function test_reconexion_no_reinicia_el_reloj_y_finalizar_es_idempotente(): void
    {
        ['token' => $token] = $this->asignarPrueba();

        $primero = $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk()->json('data.intento.vence_at');
        $this->travel(2)->minutes();
        $segundo = $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk()->json('data.intento.vence_at');
        $this->assertSame($primero, $segundo);
        $this->assertSame(1, PruebaIntento::count());

        $this->postJson("/api/publico/pruebas/{$token}/finalizar")->assertOk();
        $this->postJson("/api/publico/pruebas/{$token}/finalizar")->assertOk();
        $this->assertSame(1, PruebaIntento::whereNotNull('finalizado_at')->count());
    }

    public function test_vencido_el_tiempo_se_cierra_y_no_acepta_respuestas(): void
    {
        ['token' => $token, 'postulacion' => $postulacion, 'rys' => $rys] = $this->asignarPrueba(5);

        $pregunta = $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk()->json('data.preguntas.0');
        $this->postJson("/api/publico/pruebas/{$token}/respuestas", ['pregunta_id' => $pregunta['id'], 'opcion_id' => $pregunta['opciones'][0]['id']])->assertOk();

        $this->travel(6)->minutes();
        $this->postJson("/api/publico/pruebas/{$token}/respuestas", ['pregunta_id' => $pregunta['id'], 'opcion_id' => $pregunta['opciones'][1]['id']])->assertStatus(422);

        $intento = PruebaIntento::firstOrFail();
        $this->assertNotNull($intento->finalizado_at);
        $this->assertSame('tiempo', $intento->cierre);
        $this->assertSame(50.0, $intento->puntaje);                       // 1 de 2 correctas
        $this->assertSame($pregunta['opciones'][0]['id'], IntentoRespuesta::first()->opcion_id); // la tardía no se guardó

        $detalle = $this->withHeaders($this->cabeceras($rys))->getJson("/api/seleccion/postulaciones/{$postulacion->id}");
        $detalle->assertOk()->assertJsonPath('data.pruebas.0.intento.puntaje', 50);
    }

    public function test_comando_cierra_intentos_vencidos(): void
    {
        ['token' => $token] = $this->asignarPrueba(5);
        $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk();

        $this->travel(10)->minutes();
        $this->artisan('reclutamiento:cerrar-intentos-vencidos')->assertSuccessful();

        $this->assertSame('tiempo', PruebaIntento::firstOrFail()->cierre);
    }

    public function test_validar_exige_fuente_documentada(): void
    {
        ['rys' => $rys, 'prueba' => $prueba] = $this->asignarPrueba();
        $headers = $this->cabeceras($rys);

        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/validar", ['motivo' => 'ok'])->assertStatus(422);
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/validar", [
            'motivo' => 'Baremo aprobado por psicóloga colegiada, informe técnico 2026-04',
        ])->assertOk()->assertJsonPath('data.validada', true);
        $this->withHeaders($headers)->putJson("/api/seleccion/pruebas/{$prueba['id']}", [
            'nombre' => 'x', 'tipo' => 'conocimientos', 'tiempo_limite_minutos' => 5, 'dimensiones' => [['clave' => 'a', 'nombre' => 'a', 'peso' => 1]],
        ])->assertStatus(422);
    }
}
