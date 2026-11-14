<?php

namespace Tests\Feature\Modules\Reclutamiento;

use App\Models\User;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreaEscenarioSeleccion;
use Tests\TestCase;

/**
 * Banco único de Agento: se importa una vez, se usa en procesos de
 * cualquier empresa, se envía al postular y RR. HH. ve la hoja de
 * resultados con niveles, validez y el filtro del proceso.
 */
class BancoPruebasAgentoTest extends TestCase
{
    use CreaEscenarioSeleccion, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeleccion();
    }

    /**
     * Versión reducida del banco real: 2 afirmaciones por dimensión (rango
     * 2–10), una invertida, y escala de validez.
     */
    private function archivoBanco(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('banco.json', json_encode([
            'nombre' => 'Evaluación de Personalidad Laboral',
            'escala' => ['min' => 1, 'max' => 5, 'etiquetas' => ['1' => 'Totalmente en desacuerdo', '2' => 'En desacuerdo', '3' => 'Ni de acuerdo ni en desacuerdo', '4' => 'De acuerdo', '5' => 'Totalmente de acuerdo']],
            'dimensiones' => [
                ['codigo' => 'RES', 'nombre' => 'Responsabilidad y organización', 'interpretacion' => ['alto' => 'Cumple plazos.', 'bajo' => 'Puede descuidar plazos.', 'pregunta_entrevista' => '¿Cómo se organizó?']],
                ['codigo' => 'EQU', 'nombre' => 'Trabajo en equipo', 'interpretacion' => ['alto' => 'Coopera.', 'bajo' => 'Prefiere trabajar aislado.', 'pregunta_entrevista' => 'Hábleme de un desacuerdo.']],
                ['codigo' => 'DES', 'nombre' => 'Deseabilidad social'],
            ],
            'puntuacion' => ['niveles' => ['bajo' => [2, 4], 'medio' => [5, 7], 'alto' => [8, 10]], 'validez' => ['dimension' => 'DES', 'alerta_desde' => 9]],
            'perfiles_puesto' => [['nombre' => 'Atención al cliente', 'dimensiones' => ['RES', 'EQU']]],
            'preguntas' => [
                ['id' => 1, 'dimension' => 'RES', 'texto' => 'Cumplo mis tareas en el plazo.', 'invertida' => false],
                ['id' => 2, 'dimension' => 'RES', 'texto' => 'Dejo todo para el último momento.', 'invertida' => true],
                ['id' => 3, 'dimension' => 'EQU', 'texto' => 'Ayudo a mis compañeros.', 'invertida' => false],
                ['id' => 4, 'dimension' => 'EQU', 'texto' => 'Disfruto colaborar.', 'invertida' => false],
                ['id' => 5, 'dimension' => 'DES', 'texto' => 'Nunca he llegado tarde.', 'invertida' => false],
                ['id' => 6, 'dimension' => 'DES', 'texto' => 'Nunca me he equivocado.', 'invertida' => false],
            ],
        ]));
    }

    /**
     * Importar es una vista previa: no guarda. El contenido se revisa en el
     * editor y se guarda como borrador del banco de Agento.
     *
     * @return array{admin: User, prueba: array}
     */
    private function bancoPublicado(): array
    {
        $admin = $this->usuarioCon($this->crearEmpresa('Agento'), 'administrador');
        $headers = $this->cabeceras($admin);
        $vista = $this->withHeaders($headers)
            ->post('/api/seleccion/pruebas/importar', ['archivo' => $this->archivoBanco()], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        $this->assertSame(0, Prueba::withoutGlobalScopes()->count(), 'la vista previa no guarda');

        $prueba = $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [...$vista['contenido'], 'banco_agento' => true])
            ->assertCreated()->assertJsonPath('data.origen', 'agento')->json('data');
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/publicar")->assertOk();

        return ['admin' => $admin, 'prueba' => $prueba];
    }

    public function test_solo_un_administrador_de_agento_agrega_y_administra_el_banco(): void
    {
        ['prueba' => $prueba] = $this->bancoPublicado();
        $this->assertSame([5.0, 4.0, 3.0, 2.0, 1.0], array_map('floatval', array_column($prueba['preguntas'][1]['opciones'], 'valor')), 'la invertida se genera al revés');

        $cliente = $this->crearEmpresa('Cliente');
        $rys = $this->usuarioCon($cliente, 'talento_cultura');
        $headers = $this->cabeceras($rys);

        $vista = $this->withHeaders($headers)->post('/api/seleccion/pruebas/importar', ['archivo' => $this->archivoBanco()], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [...$vista['contenido'], 'banco_agento' => true])->assertForbidden();
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/archivar")->assertForbidden();

        // El RYS de cualquier empresa ve y usa la prueba del banco.
        $listado = $this->withHeaders($headers)->getJson('/api/seleccion/pruebas?estado=publicada')->assertOk()->json('data');
        $this->assertSame([$prueba['id']], array_column($listado, 'id'));
        $this->assertSame('agento', $listado[0]['origen']);
    }

    public function test_al_postular_se_envia_la_prueba_y_rrhh_ve_la_hoja_de_resultados_con_el_filtro(): void
    {
        ['prueba' => $prueba] = $this->bancoPublicado();

        $cliente = $this->crearEmpresa('Cliente');
        $rys = $this->usuarioCon($cliente, 'talento_cultura');
        $headers = $this->cabeceras($rys);
        $proceso = $this->procesoPublicado($rys, $this->crearArea($cliente));

        $this->withHeaders($headers)->putJson("/api/seleccion/procesos/{$proceso->id}/evaluacion-psicometrica", [
            'prueba_familia' => $prueba['familia'], 'nivel_minimo' => 'medio', 'dimensiones' => ['RES', 'EQU'],
        ])->assertOk()
            ->assertJsonPath('data.evaluacion_psicometrica.prueba.id', $prueba['id'])
            ->assertJsonPath('data.evaluacion_psicometrica.dimensiones', ['RES', 'EQU']);

        $this->postular($proceso, 'rosa@correo.test')->assertCreated();
        $postulacion = Postulacion::withoutGlobalScopes()->where('proceso_id', $proceso->id)->firstOrFail();
        $asignacion = PruebaAsignacion::withoutGlobalScopes()->where('postulacion_id', $postulacion->id)->firstOrFail();
        $this->assertSame($prueba['id'], $asignacion->prueba_id);
        $this->assertNull($asignacion->asignado_por, 'la asigna el sistema');

        $cuerpo = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        $this->assertTrue((bool) preg_match('#/empleos/prueba/([A-Za-z0-9]+)#', $cuerpo, $m));
        $token = $m[1];

        // RES: 5 + (invertida, "Totalmente en desacuerdo" = 5) = 10 → alto.
        // EQU: 1 + 1 = 2 → bajo. DES: 5 + 5 = 10 ≥ 9 → alerta de validez.
        $preguntas = $this->postJson("/api/publico/pruebas/{$token}/iniciar")->assertOk()->json('data.preguntas');
        $elegir = [4, 0, 0, 0, 4, 4];
        foreach ($preguntas as $i => $pregunta) {
            $this->postJson("/api/publico/pruebas/{$token}/respuestas", [
                'pregunta_id' => $pregunta['id'], 'opcion_id' => $pregunta['opciones'][$elegir[$i]]['id'],
            ])->assertOk();
        }
        $this->postJson("/api/publico/pruebas/{$token}/finalizar")->assertOk();

        $hoja = $this->withHeaders($headers)->getJson("/api/seleccion/postulaciones/{$postulacion->id}")->assertOk()->json('data.pruebas.0');
        $this->assertSame(60.0, (float) $hoja['intento']['puntaje'], 'la validez no suma: (RES 100 % + EQU 20 %) / 2');
        $dimensiones = collect($hoja['resultado']['dimensiones'])->keyBy('codigo');
        $this->assertSame([10.0, 'alto', 'Cumple plazos.'], [(float) $dimensiones['RES']['suma'], $dimensiones['RES']['nivel'], $dimensiones['RES']['interpretacion']]);
        $this->assertSame([2.0, 'bajo', 'Prefiere trabajar aislado.'], [(float) $dimensiones['EQU']['suma'], $dimensiones['EQU']['nivel'], $dimensiones['EQU']['interpretacion']]);
        $this->assertTrue($hoja['resultado']['validez']['alerta']);
        $this->assertFalse($hoja['resultado']['filtro']['cumple']);
        $this->assertSame(['Trabajo en equipo'], $hoja['resultado']['filtro']['incumplidas']);

        $tarjeta = collect($this->withHeaders($headers)->getJson("/api/seleccion/procesos/{$proceso->id}/tablero")->assertOk()->json('data'))
            ->flatMap(fn ($c) => $c['postulaciones'])->firstWhere('id', $postulacion->id);
        $this->assertSame(['estado' => 'no_cumple', 'alerta_validez' => true], $tarjeta['psicometria']);

        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/asignaciones/{$hoja['id']}/conclusion", [
            'conclusion' => 'recomendada_con_reservas', 'comentario' => 'Profundizar trabajo en equipo en entrevista.',
        ])->assertOk()->assertJsonPath('data.conclusion', 'recomendada_con_reservas');
        $this->withHeaders($headers)->getJson("/api/seleccion/postulaciones/{$postulacion->id}")
            ->assertJsonPath('data.pruebas.0.resultado.conclusion.valor', 'recomendada_con_reservas');
    }

    public function test_sin_prueba_configurada_no_se_envia_nada_al_postular(): void
    {
        $this->bancoPublicado();
        $cliente = $this->crearEmpresa('Cliente');
        $rys = $this->usuarioCon($cliente, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($cliente));

        $this->postular($proceso, 'luis@correo.test')->assertCreated();

        $this->assertSame(0, PruebaAsignacion::withoutGlobalScopes()->count());
    }

    public function test_la_prueba_de_otra_empresa_no_se_puede_configurar(): void
    {
        $empresaA = $this->crearEmpresa('A');
        $rysA = $this->usuarioCon($empresaA, 'talento_cultura');
        $propia = $this->withHeaders($this->cabeceras($rysA))->postJson('/api/seleccion/pruebas', [
            'nombre' => 'Prueba propia de A', 'tipo' => 'conocimientos', 'tiempo_limite_minutos' => 10,
            'dimensiones' => [['clave' => 'd1', 'nombre' => 'General', 'peso' => 1]],
            'preguntas' => [['dimension' => 'd1', 'enunciado' => '¿2 + 2?', 'opciones' => [['texto' => '4', 'valor' => 1], ['texto' => '5', 'valor' => 0]]]],
        ])->assertCreated()->json('data');
        $this->withHeaders($this->cabeceras($rysA))->postJson("/api/seleccion/pruebas/{$propia['id']}/publicar")->assertOk();

        $empresaB = $this->crearEmpresa('B');
        $rysB = $this->usuarioCon($empresaB, 'talento_cultura');
        $proceso = $this->procesoPublicado($rysB, $this->crearArea($empresaB));

        $this->withHeaders($this->cabeceras($rysB))->putJson("/api/seleccion/procesos/{$proceso->id}/evaluacion-psicometrica", [
            'prueba_familia' => $propia['familia'],
        ])->assertUnprocessable();
        $this->assertSame([], array_column($this->withHeaders($this->cabeceras($rysB))->getJson('/api/seleccion/pruebas')->json('data'), 'id'));
    }

    public function test_plantilla_excel_se_descarga_y_se_vuelve_a_importar(): void
    {
        $admin = $this->usuarioCon($this->crearEmpresa('Agento'), 'administrador');
        $headers = $this->cabeceras($admin);

        $plantilla = $this->withHeaders($headers)->get('/api/seleccion/pruebas/plantilla')->assertOk()->streamedContent();
        $archivo = UploadedFile::fake()->createWithContent('banco.xlsx', $plantilla);

        $vista = $this->withHeaders($headers)->post('/api/seleccion/pruebas/importar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertOk()->json('data.contenido');

        $this->assertSame('Evaluación de Personalidad Laboral', $vista['nombre']);
        $this->assertCount(5, $vista['escala']);
        $this->assertSame(['RES', 'DES'], array_column($vista['dimensiones'], 'codigo'));
        $this->assertTrue($vista['dimensiones'][1]['es_validez']);
        $this->assertSame(['escala', 'escala', 'escala', 'opciones'], array_column($vista['preguntas'], 'tipo'));
        $this->assertTrue($vista['preguntas'][1]['invertida']);
        $this->assertSame([0.0, 1.0, 0.0], array_column($vista['preguntas'][3]['opciones'], 'valor'));
    }

    public function test_afirmaciones_pegadas_desde_un_pdf_quedan_para_asignar_dimension(): void
    {
        $admin = $this->usuarioCon($this->crearEmpresa('Agento'), 'administrador');
        $texto = "1. Prefiero trabajar sola(o) antes que depender de un equipo. (R)\n2. Resuelvo los desacuerdos mediante el diálogo.\n\n3) Me motiva aprender.";

        $vista = $this->withHeaders($this->cabeceras($admin))->postJson('/api/seleccion/pruebas/importar', ['texto' => $texto, 'nombre' => 'Personalidad'])
            ->assertOk()->json('data');

        $this->assertSame(['Prefiero trabajar sola(o) antes que depender de un equipo.', 'Resuelvo los desacuerdos mediante el diálogo.', 'Me motiva aprender.'], array_column($vista['contenido']['preguntas'], 'enunciado'));
        $this->assertSame([true, false, false], array_column($vista['contenido']['preguntas'], 'invertida'));
        $this->assertNull($vista['contenido']['preguntas'][0]['dimension']);
        $this->assertNotEmpty($vista['advertencias']);
    }

    public function test_preguntas_con_imagen_se_muestran_al_candidato_por_url_firmada(): void
    {
        Storage::fake('local');
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $headers = $this->cabeceras($rys);

        $imagen = $this->withHeaders($headers)->post('/api/seleccion/pruebas/imagenes', ['imagen' => UploadedFile::fake()->image('figura.png', 40, 40)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->get($imagen['url'])->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $imagen['url']))->assertForbidden();
        $this->get("/api/publico/pruebas/imagenes/{$imagen['id']}")->assertForbidden();

        $prueba = $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [
            'nombre' => 'Razonamiento visual', 'tipo' => 'conocimientos', 'tiempo_limite_minutos' => 10,
            'dimensiones' => [['clave' => 'd1', 'nombre' => 'Razonamiento', 'peso' => 1]],
            'preguntas' => [
                ['tipo' => 'opciones', 'dimension' => 'd1', 'enunciado' => '¿Qué figura sigue?', 'imagen_id' => $imagen['id'], 'opciones' => [
                    ['imagen_id' => $imagen['id'], 'valor' => 1], ['texto' => 'Ninguna', 'valor' => 0],
                ]],
            ],
        ])->assertCreated()->json('data');
        $this->assertNotNull($prueba['preguntas'][0]['imagen_url']);
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/publicar")->assertOk();

        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $postulacion = $this->postulacionEn($proceso, 'psicometrica', 'activa', ['email' => 'visual@correo.test']);
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/pruebas/{$prueba['id']}")->assertCreated();
        $cuerpo = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('#/empleos/prueba/([A-Za-z0-9]+)#', $cuerpo, $m);

        $pregunta = $this->postJson("/api/publico/pruebas/{$m[1]}/iniciar")->assertOk()->json('data.preguntas.0');
        $this->assertNotNull($pregunta['imagen_url']);
        $this->assertNotNull($pregunta['opciones'][0]['imagen_url']);
        $this->assertArrayNotHasKey('invertida', $pregunta);
        $this->get($pregunta['imagen_url'])->assertOk();
    }

    public function test_no_se_puede_usar_la_imagen_de_otra_empresa(): void
    {
        Storage::fake('local');
        $empresaA = $this->crearEmpresa('A');
        $imagen = $this->withHeaders($this->cabeceras($this->usuarioCon($empresaA, 'talento_cultura')))
            ->post('/api/seleccion/pruebas/imagenes', ['imagen' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');

        $rysB = $this->usuarioCon($this->crearEmpresa('B'), 'talento_cultura');
        $this->withHeaders($this->cabeceras($rysB))->postJson('/api/seleccion/pruebas', [
            'nombre' => 'Ajena', 'tipo' => 'conocimientos', 'tiempo_limite_minutos' => 10,
            'dimensiones' => [['clave' => 'd1', 'nombre' => 'General', 'peso' => 1]],
            'preguntas' => [['tipo' => 'opciones', 'dimension' => 'd1', 'enunciado' => '¿?', 'imagen_id' => $imagen['id'], 'opciones' => [['texto' => 'A', 'valor' => 1], ['texto' => 'B', 'valor' => 0]]]],
        ])->assertUnprocessable();
    }

    public function test_desactivar_activar_y_eliminar_respetan_los_resultados_registrados(): void
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $headers = $this->cabeceras($rys);
        $crear = fn (string $nombre) => $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [
            'nombre' => $nombre, 'tipo' => 'conocimientos', 'tiempo_limite_minutos' => 10,
            'dimensiones' => [['clave' => 'd1', 'nombre' => 'General', 'peso' => 1]],
            'preguntas' => [['tipo' => 'opciones', 'dimension' => 'd1', 'enunciado' => '¿2 + 2?', 'opciones' => [['texto' => '4', 'valor' => 1], ['texto' => '5', 'valor' => 0]]]],
        ])->assertCreated()->json('data');

        // Sin uso: desactivar → activar vuelve a borrador; se puede eliminar.
        $borrador = $crear('Sin usar');
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$borrador['id']}/archivar")->assertOk()->assertJsonPath('data.estado', 'archivada');
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$borrador['id']}/reactivar")->assertOk()->assertJsonPath('data.estado', 'borrador');
        $this->withHeaders($headers)->deleteJson("/api/seleccion/pruebas/{$borrador['id']}")->assertOk();
        $this->assertNull(Prueba::withoutGlobalScopes()->find($borrador['id']));

        // Rendida por un candidato: no se elimina; activar la vuelve a publicar.
        $usada = $crear('Usada');
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$usada['id']}/publicar")->assertOk();
        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $postulacion = $this->postulacionEn($proceso, 'psicometrica');
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/pruebas/{$usada['id']}")->assertCreated();

        $this->assertSame(1, collect($this->withHeaders($headers)->getJson('/api/seleccion/pruebas')->json('data'))->firstWhere('id', $usada['id'])['usos']);
        $this->withHeaders($headers)->deleteJson("/api/seleccion/pruebas/{$usada['id']}")->assertUnprocessable();
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$usada['id']}/archivar")->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$usada['id']}/reactivar")->assertOk()->assertJsonPath('data.estado', 'publicada');
    }

    public function test_no_se_elimina_la_prueba_que_un_proceso_abierto_envia_al_postular(): void
    {
        ['admin' => $admin, 'prueba' => $prueba] = $this->bancoPublicado();
        $cliente = $this->crearEmpresa('Cliente');
        $rys = $this->usuarioCon($cliente, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($cliente));
        $this->withHeaders($this->cabeceras($rys))->putJson("/api/seleccion/procesos/{$proceso->id}/evaluacion-psicometrica", [
            'prueba_familia' => $prueba['familia'],
        ])->assertOk();

        $this->withHeaders($this->cabeceras($admin))->deleteJson("/api/seleccion/pruebas/{$prueba['id']}")
            ->assertUnprocessable()->assertJsonFragment(['message' => "Los procesos {$proceso->codigo} envían esta prueba al postular. Quítala de esos procesos o publica otra versión antes de eliminarla."]);
    }

    /**
     * @return array{rys: User, headers: array, proceso: \App\Modules\Reclutamiento\Models\ProcesoSeleccion, prueba: array}
     */
    private function procesoConPruebaDeConocimientos(string $momento, ?int $minimo = null): array
    {
        $empresa = $this->crearEmpresa();
        $rys = $this->usuarioCon($empresa, 'talento_cultura');
        $headers = $this->cabeceras($rys);
        $prueba = $this->withHeaders($headers)->postJson('/api/seleccion/pruebas', [
            'nombre' => 'Contabilidad básica', 'tipo' => 'conocimientos', 'area_conocimiento' => 'Contabilidad', 'tiempo_limite_minutos' => 15,
            'dimensiones' => [['clave' => 't1', 'nombre' => 'Asientos contables', 'peso' => 1]],
            'preguntas' => [
                ['tipo' => 'opciones', 'dimension' => 't1', 'enunciado' => '¿El IGV es un pasivo?', 'opciones' => [['texto' => 'Sí', 'valor' => 1], ['texto' => 'No', 'valor' => 0]]],
                ['tipo' => 'opciones', 'dimension' => 't1', 'enunciado' => '¿La caja es un activo?', 'opciones' => [['texto' => 'Sí', 'valor' => 1], ['texto' => 'No', 'valor' => 0]]],
            ],
        ])->assertCreated()->assertJsonPath('data.area_conocimiento', 'Contabilidad')->json('data');
        $this->withHeaders($headers)->postJson("/api/seleccion/pruebas/{$prueba['id']}/publicar")->assertOk();

        $proceso = $this->procesoPublicado($rys, $this->crearArea($empresa));
        $this->withHeaders($headers)->putJson("/api/seleccion/procesos/{$proceso->id}/evaluacion-conocimientos", [
            'prueba_familia' => $prueba['familia'], 'momento' => $momento, 'puntaje_minimo' => $minimo,
        ])->assertOk()
            ->assertJsonPath('data.evaluacion_conocimientos.momento', $momento)
            ->assertJsonPath('data.evaluacion_conocimientos.prueba.area_conocimiento', 'Contabilidad');

        return ['rys' => $rys, 'headers' => $headers, 'proceso' => $proceso, 'prueba' => $prueba];
    }

    public function test_la_prueba_de_conocimientos_se_envia_al_pasar_el_filtro_de_cv(): void
    {
        ['headers' => $headers, 'proceso' => $proceso, 'prueba' => $prueba] = $this->procesoConPruebaDeConocimientos('filtro_cv', 60);

        $this->postular($proceso, 'contadora@correo.test')->assertCreated();
        $postulacion = Postulacion::withoutGlobalScopes()->where('proceso_id', $proceso->id)->firstOrFail();
        $this->assertSame(0, PruebaAsignacion::withoutGlobalScopes()->count(), 'aún no pasa el filtro de CV');

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'filtro_cv'])->assertOk();
        $this->assertSame(0, PruebaAsignacion::withoutGlobalScopes()->count());

        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'psicometrica'])->assertOk();
        $this->assertSame([$prueba['id']], PruebaAsignacion::withoutGlobalScopes()->pluck('prueba_id')->all());

        // Volver a pasar por el filtro no la envía dos veces.
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'filtro_cv', 'motivo' => 'Revisar CV'])->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/postulaciones/{$postulacion->id}/mover", ['etapa' => 'psicometrica'])->assertOk();
        $this->assertSame(1, PruebaAsignacion::withoutGlobalScopes()->count());

        // Responde 1 de 2 bien: 50 % < mínimo 60 % → no cumple.
        $cuerpo = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        preg_match('#/empleos/prueba/([A-Za-z0-9]+)#', $cuerpo, $m);
        $preguntas = $this->postJson("/api/publico/pruebas/{$m[1]}/iniciar")->assertOk()->json('data.preguntas');
        $this->postJson("/api/publico/pruebas/{$m[1]}/respuestas", ['pregunta_id' => $preguntas[0]['id'], 'opcion_id' => $preguntas[0]['opciones'][0]['id']])->assertOk();
        $this->postJson("/api/publico/pruebas/{$m[1]}/respuestas", ['pregunta_id' => $preguntas[1]['id'], 'opcion_id' => $preguntas[1]['opciones'][1]['id']])->assertOk();
        $this->postJson("/api/publico/pruebas/{$m[1]}/finalizar")->assertOk();

        $filtro = $this->withHeaders($headers)->getJson("/api/seleccion/postulaciones/{$postulacion->id}")->assertOk()->json('data.pruebas.0.resultado.filtro');
        $this->assertFalse($filtro['cumple']);
        $this->assertSame(60, $filtro['puntaje_minimo']);
        $this->assertSame(50.0, (float) $filtro['puntaje']);
    }

    public function test_la_prueba_de_conocimientos_puede_enviarse_al_postular(): void
    {
        ['proceso' => $proceso, 'prueba' => $prueba] = $this->procesoConPruebaDeConocimientos('postular');

        $this->postular($proceso, 'contador@correo.test')->assertCreated();

        $this->assertSame([$prueba['id']], PruebaAsignacion::withoutGlobalScopes()->pluck('prueba_id')->all());
    }

    public function test_no_se_configura_una_prueba_psicometrica_como_de_conocimientos(): void
    {
        ['prueba' => $prueba] = $this->bancoPublicado();
        $cliente = $this->crearEmpresa('Cliente');
        $rys = $this->usuarioCon($cliente, 'talento_cultura');
        $proceso = $this->procesoPublicado($rys, $this->crearArea($cliente));

        $this->withHeaders($this->cabeceras($rys))->putJson("/api/seleccion/procesos/{$proceso->id}/evaluacion-conocimientos", [
            'prueba_familia' => $prueba['familia'],
        ])->assertUnprocessable();
    }
}
