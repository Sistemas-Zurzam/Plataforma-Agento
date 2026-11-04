<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\CodigoVerificacion;
use App\Modules\Reclutamiento\Models\EvaluacionAts;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

class PortalEmpleosTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
        Storage::fake('local');
    }

    private function ultimoCodigoEnviado(): string
    {
        $mensaje = Mail::mailer('array')->getSymfonyTransport()->messages()->last();
        preg_match('/\b(\d{6})\b/', $mensaje->getOriginalMessage()->getTextBody(), $coincidencia);

        return $coincidencia[1];
    }

    public function test_flujo_de_verificacion_y_postulacion(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));

        $this->postJson('/api/publico/candidatos/codigo', ['email' => 'Rosa@Correo.test'])->assertOk();
        $token = $this->postJson('/api/publico/candidatos/verificar', ['email' => 'rosa@correo.test', 'codigo' => $this->ultimoCodigoEnviado()])
            ->assertOk()->json('data.token');

        $respuesta = $this->withHeaders(['X-Candidato-Token' => $token])
            ->post("/api/publico/empleos/{$proceso->codigo}/postulaciones", $this->datosPostulacion($proceso), ['Accept' => 'application/json'])
            ->assertCreated();

        $postulacion = Postulacion::withoutGlobalScopes()->where('codigo_seguimiento', $respuesta->json('data.codigo_seguimiento'))->firstOrFail();
        $this->assertSame($empresa->id, $postulacion->empresa_id);
        $this->assertSame('rosa@correo.test', $postulacion->candidato()->withoutGlobalScopes()->first()->email);

        // El cálculo ATS inicial corre en cola (sync en pruebas) por reglas declaradas.
        $ats = EvaluacionAts::where('postulacion_id', $postulacion->id)->firstOrFail();
        $this->assertSame('reglas_declaradas', $ats->origen);
        $this->assertSame('apto', $ats->resultado);

        $this->withHeaders(['X-Candidato-Token' => $token])->getJson('/api/publico/candidatos/postulaciones')
            ->assertOk()->assertJsonPath('data.0.estado_publico', 'Postulación recibida')
            ->assertJsonMissingPath('data.0.motivo_interno');
    }

    public function test_solicitar_codigo_no_revela_si_el_correo_existe(): void
    {
        $empresa = $this->crearEmpresa();
        Candidato::withoutGlobalScopes()->create(['empresa_id' => $empresa->id, 'email' => 'existe@correo.test', 'nombres' => 'A', 'apellido_paterno' => 'B']);

        $existente = $this->postJson('/api/publico/candidatos/codigo', ['email' => 'existe@correo.test'])->assertOk()->json();
        $inexistente = $this->postJson('/api/publico/candidatos/codigo', ['email' => 'nuevo@correo.test'])->assertOk()->json();

        $this->assertSame($existente, $inexistente);
    }

    public function test_codigo_vencido_o_con_demasiados_intentos_no_sirve(): void
    {
        $this->postJson('/api/publico/candidatos/codigo', ['email' => 'a@correo.test'])->assertOk();
        $codigo = $this->ultimoCodigoEnviado();

        $this->travel(11)->minutes();
        $this->postJson('/api/publico/candidatos/verificar', ['email' => 'a@correo.test', 'codigo' => $codigo])->assertStatus(422);
        $this->travelBack();

        $this->postJson('/api/publico/candidatos/codigo', ['email' => 'b@correo.test'])->assertOk();
        $codigoB = $this->ultimoCodigoEnviado();
        $incorrecto = $codigoB === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/publico/candidatos/verificar', ['email' => 'b@correo.test', 'codigo' => $incorrecto]);
        }
        // Agotados los intentos, ni el código correcto sirve.
        $this->assertNotNull(CodigoVerificacion::latest('id')->first()->consumido_at);
        $this->postJson('/api/publico/candidatos/verificar', ['email' => 'b@correo.test', 'codigo' => $codigoB])->assertStatus(422);
    }

    public function test_solicitud_de_codigos_tiene_rate_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/publico/candidatos/codigo', ['email' => 'limite@correo.test'])->assertOk();
        }
        $this->postJson('/api/publico/candidatos/codigo', ['email' => 'limite@correo.test'])->assertStatus(429);
    }

    public function test_codigo_de_seguimiento_no_da_acceso_a_datos(): void
    {
        $this->getJson('/api/publico/candidatos/postulaciones')->assertUnauthorized();
        $this->withHeaders(['X-Candidato-Token' => 'PABCDEFGHI'])->getJson('/api/publico/candidatos/postulaciones')->assertUnauthorized();
    }

    public function test_no_puede_postular_dos_veces_al_mismo_proceso_pero_si_a_otro(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);
        $proceso = $this->procesoPublicado($rys, $area);
        $otro = $this->procesoPublicado($rys, $area);

        $this->postular($proceso, 'dup@correo.test')->assertCreated();
        $this->postular($proceso, 'dup@correo.test')->assertStatus(422);
        $this->postular($otro, 'dup@correo.test')->assertCreated();

        $this->assertSame(2, Postulacion::withoutGlobalScopes()->count());
    }

    public function test_cv_que_no_es_pdf_real_es_rechazado(): void
    {
        $empresa = $this->crearEmpresa();
        $proceso = $this->procesoPublicado($this->usuarioCon($empresa, 'talento_cultura'), $this->crearArea($empresa));
        $falso = UploadedFile::fake()->createWithContent('cv.pdf', 'MZ ejecutable disfrazado');

        $this->postular($proceso, 'x@correo.test', ['cv' => $falso])->assertStatus(422)->assertJsonValidationErrors('cv');
        $this->assertSame(0, Postulacion::withoutGlobalScopes()->count());
    }

    public function test_publicacion_interna_no_es_publica_sin_invitacion(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa), 1, externa: false);

        $this->getJson('/api/publico/empleos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/publico/empleos/{$proceso->codigo}")->assertNotFound();

        $this->withHeaders($this->cabeceras($rys))->postJson("/api/seleccion/procesos/{$proceso->id}/invitaciones", [
            'tipo' => 'interna', 'email' => 'colaborador@empresa.test',
        ])->assertCreated();
        $mensaje = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('/invitacion=([A-Za-z0-9]+)/', $mensaje, $m);

        $this->getJson("/api/publico/empleos/{$proceso->codigo}?invitacion={$m[1]}")->assertOk();
        $this->postular($proceso, 'otro@correo.test', ['invitacion' => $m[1]])->assertStatus(422);
        $this->postular($proceso, 'colaborador@empresa.test', ['invitacion' => $m[1]])->assertCreated();
        $this->assertSame('portal_interno', Postulacion::withoutGlobalScopes()->first()->fuente);
    }

    public function test_publicacion_confidencial_no_muestra_empresa(): void
    {
        $empresa = $this->crearEmpresa('Secreta');
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $this->withHeaders($this->cabeceras($rys))->putJson("/api/seleccion/procesos/{$proceso->id}", ['empresa_confidencial' => true])->assertOk();

        $respuesta = $this->getJson("/api/publico/empleos/{$proceso->codigo}")->assertOk()->assertJsonPath('data.empresa', null);
        $this->assertStringNotContainsString($empresa->nombre_comercial, $respuesta->getContent());
        $this->assertStringNotContainsString('umbral', $respuesta->getContent());
    }

    public function test_snapshot_no_cambia_al_actualizar_el_perfil(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $area = $this->crearArea($empresa);
        $primero = $this->procesoPublicado($rys, $area);
        $segundo = $this->procesoPublicado($rys, $area);

        $this->postular($primero, 'snap@correo.test', ['celular' => '911111111'])->assertCreated();
        $this->postular($segundo, 'snap@correo.test', ['celular' => '922222222'])->assertCreated();

        $postulaciones = Postulacion::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame('911111111', $postulaciones[0]->perfil_snapshot['celular']);
        $this->assertSame('922222222', $postulaciones[1]->perfil_snapshot['celular']);
        $this->assertNotSame($postulaciones[0]->cv_documento_id, $postulaciones[1]->cv_documento_id);
    }
}
