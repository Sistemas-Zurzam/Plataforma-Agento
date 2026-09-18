<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Services\CarnetCredentialService;
use App\Modules\Asistencia\Services\RegistrarMarcacionCarnetService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Marcación de asistencia por carnet/código de barras — extremo a extremo.
 * No existía ningún test previo que ejercitara ProcesarAsistenciaDiaria vía
 * HTTP real con marcaciones creadas por request (ver Fase 0), así que el
 * helper de setup (crearColaboradorConHorario) se arma desde cero siguiendo
 * el patrón real de HorarioSeeder/ColaboradorHorarioAsignacion, no copiado
 * de un test existente.
 */
class ControlAccesoCarnetTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::where('username', 'test.user')->firstOrFail();

        return [$empresa, $usuario];
    }

    /** Horario Lu-Sá 08:00-17:00 (normal) o 22:00-06:00 (nocturno), vigente desde antes de fecha_ingreso. */
    private function crearColaboradorConHorario(Empresa $empresa, bool $nocturno = false): Colaborador
    {
        $colaborador = $this->crearColaborador($empresa);

        $horario = Horario::create([
            'empresa_id' => $empresa->id,
            'nombre' => $nocturno ? 'Nocturno Test' : 'Normal Test',
            'tipo_turno' => 'normal',
            'cruza_medianoche' => $nocturno,
            'vigencia_desde' => $colaborador->fecha_ingreso->copy()->subYear(),
            'activo' => true,
        ]);

        foreach (range(0, 6) as $diaSemana) {
            $horario->dias()->create([
                'dia_semana' => $diaSemana,
                'estado' => $diaSemana === 6 ? 'descanso' : 'laborable',
                'hora_entrada' => $diaSemana === 6 ? null : ($nocturno ? '22:00' : '08:00'),
                'hora_salida' => $diaSemana === 6 ? null : ($nocturno ? '06:00' : '17:00'),
                'refrigerio_inicio' => null,
                'refrigerio_fin' => null,
                'jornada_nocturna' => $nocturno && $diaSemana !== 6,
                'permitir_horas_extra' => true,
            ]);
        }

        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'horario_id' => $horario->id,
            'vigencia_desde' => $colaborador->fecha_ingreso,
            'vigencia_hasta' => null,
        ]);

        return $colaborador;
    }

    private function token(User $usuario): string
    {
        return JWTAuth::fromUser($usuario);
    }

    private function cabecera(User $usuario): array
    {
        return ['Authorization' => 'Bearer '.$this->token($usuario)];
    }

    /** Los horarios (HorarioDia.hora_entrada/hora_salida) son valores naive
     * en hora de Lima, igual que el resto del dominio de Asistencia
     * (FechaOperativa) — sin fijar la zona horaria acá, Carbon::parse() usa
     * config('app.timezone') (UTC), desfasando 5h el "ahora" congelado
     * contra la hora del horario configurado. */
    private function viajarA(string $fechaHoraLima): Carbon
    {
        $instante = Carbon::parse($fechaHoraLima, 'America/Lima');
        $this->travelTo($instante);

        return $instante;
    }

    /**
     * User::factory() ya auto-adjunta a su empresa_id con rol "solicitante"
     * (UserFactory::configure()) — se actualiza el pivote existente, no se
     * vuelve a adjuntar.
     *
     * ->refresh() es obligatorio: `token_version` tiene default 0 a nivel
     * de columna (migración), pero Eloquent no relee la fila tras el
     * INSERT, así que el objeto en memoria queda con `token_version` null.
     * JwtMiddleware rechaza cualquier token cuyo claim `token_version` sea
     * null (sesión invalidada) — sin el refresh, todo usuario de prueba
     * creado por factory recibía 401 antes de llegar a mi middleware de
     * permiso.
     */
    private function conPermisoControlAcceso(Empresa $empresa): User
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id])->refresh();
        $rol = Role::firstOrCreate(['clave' => 'vigilancia'], ['nombre' => 'Vigilancia']);
        $permiso = Permission::where('clave', 'control_acceso.marcar')->firstOrFail();
        $rol->permissions()->syncWithoutDetaching([$permiso->id]);
        $usuario->empresas()->updateExistingPivot($empresa->id, ['role_id' => $rol->id]);

        return $usuario;
    }

    /** El rol "solicitante" que UserFactory ya asigna por defecto no tiene ningún permiso — sirve tal cual. Ver refresh() arriba. */
    private function sinPermisos(Empresa $empresa): User
    {
        return User::factory()->create(['empresa_id' => $empresa->id])->refresh();
    }

    // --- Credencial ------------------------------------------------------

    public function test_generar_credencial_devuelve_token_una_sola_vez_y_persiste_solo_el_hash(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        ['credencial' => $credencial, 'token' => $token] = app(CarnetCredentialService::class)
            ->generar($empresa, $colaborador, $usuario);

        $this->assertNotEmpty($token);
        $this->assertSame(hash('sha256', $token), $credencial->token_hash);
        $this->assertDatabaseMissing('colaborador_credenciales_acceso', ['token_hash' => $token]);
    }

    public function test_resolver_credencial_invalida_devuelve_null(): void
    {
        [$empresa] = $this->escenario();

        $this->assertNull(app(CarnetCredentialService::class)->resolver('token-que-no-existe'));
    }

    public function test_regenerar_invalida_la_credencial_anterior(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $servicio = app(CarnetCredentialService::class);

        ['token' => $tokenViejo] = $servicio->generar($empresa, $colaborador, $usuario);
        ['token' => $tokenNuevo] = $servicio->regenerar($empresa, $colaborador, $usuario);

        $this->assertNull($servicio->resolver($tokenViejo));
        $this->assertNotNull($servicio->resolver($tokenNuevo));
    }

    public function test_revocar_credencial_impide_que_siga_funcionando(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $servicio = app(CarnetCredentialService::class);
        ['credencial' => $credencial, 'token' => $token] = $servicio->generar($empresa, $colaborador, $usuario);

        $servicio->revocar($empresa, $credencial, $usuario, 'Carnet perdido');

        $this->assertNull($servicio->resolver($token));
    }

    public function test_serializar_la_credencial_directamente_no_expone_el_hash(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['credencial' => $credencial] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        // Segunda barrera además de que ningún controlador serialice el
        // modelo directamente hoy: un toArray()/toJson() futuro tampoco
        // debe filtrar el hash.
        $this->assertArrayNotHasKey('token_hash', $credencial->toArray());
    }

    // --- Formato del token (20 dígitos numéricos) --------------------------

    public function test_el_token_generado_tiene_exactamente_20_digitos_numericos(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->assertMatchesRegularExpression('/^[0-9]{20}$/', $token);
    }

    public function test_el_token_generado_conserva_ceros_iniciales(): void
    {
        $servicio = app(CarnetCredentialService::class);
        $generarToken = (new \ReflectionClass($servicio))->getMethod('generarToken');
        $generarToken->setAccessible(true);

        // random_int() por dígito puede generar un '0' inicial; si en algún
        // punto el token pasara por un entero (en vez de construirse como
        // string desde el inicio), ese cero se perdería y strlen() bajaría
        // de 20. Se invoca generarToken() directo por reflexión (sin tocar
        // la BD) para poder muestrear muchas veces barato: con 300 muestras
        // la probabilidad de no ver ningún cero inicial es 0.9^300 ≈ 10⁻¹⁴
        // — a diferencia de la versión anterior (20 muestras vía generar(),
        // ~12% de probabilidad de fallar por pura mala suerte, y de hecho
        // falló así en una corrida real).
        $vioTokenConCeroInicial = false;
        for ($i = 0; $i < 300; $i++) {
            $token = $generarToken->invoke($servicio);
            $this->assertSame(20, strlen($token), 'El token debe conservar sus 20 dígitos incluso con ceros a la izquierda.');
            if (str_starts_with($token, '0')) {
                $vioTokenConCeroInicial = true;
            }
        }
        $this->assertTrue($vioTokenConCeroInicial, 'No se generó ningún token con cero inicial en 300 intentos — revisar generarToken().');
    }

    public function test_el_token_generado_no_contiene_letras(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->assertFalse(ctype_alpha(str_replace(range('0', '9'), '', $token) ?: '0'));
        $this->assertTrue(ctype_digit($token));
    }

    public function test_el_token_no_se_deriva_del_numero_documento_del_colaborador(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaborador($empresa, ['numero_documento' => '87654321']);

        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->assertNotSame($colaborador->numero_documento, $token);
        $this->assertStringNotContainsString($colaborador->numero_documento, $token);
    }

    public function test_dos_generaciones_producen_tokens_distintos(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaboradorA = $this->crearColaboradorConHorario($empresa);
        $colaboradorB = $this->crearColaboradorConHorario($empresa);
        $servicio = app(CarnetCredentialService::class);

        ['token' => $tokenA] = $servicio->generar($empresa, $colaboradorA, $usuario);
        ['token' => $tokenB] = $servicio->generar($empresa, $colaboradorB, $usuario);

        $this->assertNotSame($tokenA, $tokenB);
    }

    public function test_hash_de_la_credencial_sigue_siendo_sha256(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        ['credencial' => $credencial, 'token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->assertSame(64, strlen($credencial->token_hash));
        $this->assertSame(hash('sha256', $token), $credencial->token_hash);
    }

    // --- Validación del formato de `codigo` en el endpoint de escaneo -----

    /** @return array<string, array{0: string}> */
    public static function codigosConFormatoInvalidoProvider(): array
    {
        return [
            'DNI de 8 dígitos' => ['87654321'],
            'token hexadecimal del formato anterior (32 caracteres)' => ['a3ee28e5e69e1eaebc60660473e7fa86'],
            '19 dígitos (uno menos)' => ['1234567890123456789'],
            '21 dígitos (uno más)' => ['123456789012345678901'],
            'espacio interno' => ['1234 6789012345678901'],
            'guion interno' => ['1234-6789012345678901'],
            'letras' => ['abcdefghij0123456789'],
            'vacío' => [''],
        ];
    }

    #[DataProvider('codigosConFormatoInvalidoProvider')]
    public function test_formato_de_codigo_invalido_es_rechazado(string $codigo): void
    {
        [$empresa, $usuario] = $this->escenario();

        $this->postJson('/api/control-acceso/escanear', ['codigo' => $codigo], $this->cabecera($usuario))
            ->assertStatus(422)
            ->assertJsonValidationErrors('codigo');
    }

    public function test_codigo_con_salto_de_linea_final_de_la_pistola_se_procesa_correctamente(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');

        // Algunas pistolas keyboard-wedge pueden arrastrar un salto de línea
        // — prepareForValidation() lo recorta, no debe rechazarse ni
        // alterar el código en sí.
        $this->postJson('/api/control-acceso/escanear', ['codigo' => "{$token}\n"], $this->cabecera($usuario))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'entrada_registrada');
    }

    public function test_auditoria_de_credencial_no_guarda_el_token(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $registro = AsistenciaAuditoria::where('accion', 'credencial_generada')->firstOrFail();
        $volcado = json_encode([$registro->antes, $registro->despues]);
        $this->assertStringNotContainsString($token, $volcado);
    }

    // --- Escaneo: casos de error ------------------------------------------

    public function test_http_escanear_codigo_no_reconocido_devuelve_422_sin_exponer_detalles(): void
    {
        [$empresa, $usuario] = $this->escenario();

        // Formato válido (20 dígitos) pero que no corresponde a ninguna
        // credencial generada — caso distinto de "formato inválido" (ver
        // la batería test_formato_de_codigo_* más abajo).
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => '99999999999999999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credencial');
    }

    public function test_http_escanear_credencial_revocada_se_trata_como_no_reconocida(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $servicio = app(CarnetCredentialService::class);
        ['credencial' => $credencial, 'token' => $token] = $servicio->generar($empresa, $colaborador, $usuario);
        $servicio->revocar($empresa, $credencial, $usuario, 'Prueba');

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credencial');
    }

    public function test_http_escanear_colaborador_inactivo_es_rechazado(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);
        $colaborador->update(['activo' => false]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('colaborador');
    }

    public function test_http_escanear_credencial_de_otra_empresa_no_revela_informacion(): void
    {
        [$empresaA, $usuarioA] = $this->escenario();
        $empresaB = Empresa::where('id', '!=', $empresaA->id)->firstOrFail();
        $colaboradorB = $this->crearColaboradorConHorario($empresaB);
        ['token' => $tokenB] = app(CarnetCredentialService::class)->generar($empresaB, $colaboradorB, $usuarioA);

        // $usuarioA tiene su empresa activa = $empresaA — el token es de B.
        $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuarioA)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $tokenB])
            ->assertStatus(422);

        // Mismo mensaje/clave que "no reconocido" — no distingue "es de otra empresa".
        $respuesta->assertJsonValidationErrors('credencial');
    }

    public function test_http_usuario_sin_permiso_recibe_403(): void
    {
        [$empresa] = $this->escenario();
        $usuarioSinPermiso = $this->sinPermisos($empresa);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuarioSinPermiso)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => 'cualquiera'])
            ->assertStatus(403);
    }

    public function test_http_sin_autenticacion_recibe_401(): void
    {
        $this->postJson('/api/control-acceso/escanear', ['codigo' => 'cualquiera'])->assertStatus(401);
    }

    public function test_http_usuario_con_permiso_control_acceso_puede_escanear(): void
    {
        [$empresa, $usuarioAdmin] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuarioAdmin);
        $usuarioVigilancia = $this->conPermisoControlAcceso($empresa);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuarioVigilancia)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertOk();
    }

    // --- Escaneo: caso exitoso, jornada normal ----------------------------

    public function test_http_primer_escaneo_del_dia_registra_entrada(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $instante = $this->viajarA('2026-10-05 08:00:00'); // lunes

        $respuesta = $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertOk()
            ->assertJsonPath('data.resultado', 'entrada_registrada')
            ->assertJsonPath('data.origen', AsistenciaMarcacion::ORIGEN_CARNET_CODIGO_BARRAS);

        // El nombre/hora sí se muestran; nada de datos sensibles ni el token.
        $respuesta->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash')
            ->assertJsonMissingPath('data.credencial_id')
            ->assertJsonMissingPath('data.colaborador.numero_documento');

        $marcacion = AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->sole();
        $this->assertSame($colaborador->numero_documento, $marcacion->person_id);
        $this->assertSame(AsistenciaMarcacion::ORIGEN_CARNET_CODIGO_BARRAS, $marcacion->origen);
        // No usar equalTo(): el resto del dominio de Asistencia guarda
        // dateTime "naive" (números de reloj de Lima, releídos con el
        // timezone técnico UTC de la app — ver FechaOperativa) — comparar
        // instantes reales aquí compararía cosas de universos distintos.
        // Lo que debe coincidir es la lectura de reloj (los números).
        $this->assertSame($instante->format('Y-m-d H:i:s'), $marcacion->marcado_at->format('Y-m-d H:i:s'));
    }

    public function test_http_segundo_escaneo_del_dia_fuera_de_la_ventana_registra_salida(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])->assertOk();

        $this->viajarA('2026-10-05 17:05:00');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertOk()
            ->assertJsonPath('data.resultado', 'salida_registrada');

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $this->assertNotNull($resultado->entrada_at);
        $this->assertNotNull($resultado->salida_at);
        $this->assertGreaterThan(0, $resultado->minutos_trabajados);
    }

    public function test_http_doble_escaneo_inmediato_no_crea_una_segunda_marcacion(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertJsonPath('data.resultado', 'entrada_registrada');

        // 3 segundos después — dentro de la ventana de antirrebote (10s default).
        $this->viajarA('2026-10-05 08:00:03');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertOk()
            ->assertJsonPath('data.resultado', 'marcacion_duplicada');

        $this->assertSame(1, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
    }

    /**
     * La verdadera concurrencia (dos requests EN PARALELO) no puede probarse
     * de forma realista en SQLite/PHPUnit síncrono — este test cubre la
     * ruta de código (check-lock-recheck dentro de una sola transacción),
     * no la concurrencia real de MySQL. Verificado manualmente que el lock
     * usa `lockForUpdate()`, compatible con InnoDB; pendiente de una prueba
     * de carga real contra MySQL antes de producción (ver limitaciones).
     */
    public function test_dos_llamadas_secuenciales_con_el_mismo_codigo_dentro_de_la_ventana_no_duplican(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);
        $servicio = app(RegistrarMarcacionCarnetService::class);

        $this->viajarA('2026-10-05 08:00:00');
        $servicio->registrar($empresa, $usuario, $token);
        $servicio->registrar($empresa, $usuario, $token);

        $this->assertSame(1, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
    }

    // --- Jornada nocturna --------------------------------------------------

    public function test_http_salida_despues_de_medianoche_cierra_la_entrada_de_la_jornada_nocturna(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa, nocturno: true);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        // Entrada lunes 22:00.
        $this->viajarA('2026-10-05 22:00:00');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertJsonPath('data.resultado', 'entrada_registrada');

        // Salida martes 06:10 — más allá de la ventana de antirrebote y de medianoche.
        $this->viajarA('2026-10-06 06:10:00');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertOk()
            ->assertJsonPath('data.resultado', 'salida_registrada');

        // La jornada se asocia a LUNES (día de la entrada), no a martes.
        $resultadoLunes = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $this->assertNotNull($resultadoLunes->entrada_at);
        $this->assertNotNull($resultadoLunes->salida_at);
        $this->assertTrue($resultadoLunes->salida_at->gt($resultadoLunes->entrada_at));
    }

    // --- Período cerrado -----------------------------------------------------

    public function test_http_periodo_cerrado_rechaza_el_escaneo(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');
        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id,
            'fecha_inicio' => '2026-10-01',
            'fecha_fin' => '2026-10-31',
            'estado' => 'cerrado',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$this->token($usuario)])
            ->postJson('/api/control-acceso/escanear', ['codigo' => $token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_desde');

        $this->assertSame(0, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
    }

    // --- Tercer/cuarto escaneo, mezcla de orígenes, sesiones antiguas ------
    //
    // ProcesarAsistenciaDiaria::obtenerMarcaciones() (motor existente, sin
    // tocar) asigna entrada=primera del día y salida=última del día, sin
    // límite de cuántas marcaciones intermedias haya. Comportamiento
    // DOCUMENTADO acá, no "corregido": un 3er/4to escaneo legítimo CORRE la
    // salida hacia ese nuevo horario — no crea un segundo turno ni lo
    // rechaza. Si el negocio necesita otra regla, es una decisión de
    // producto para ResolverJornadaDiaria, fuera de esta ronda.

    public function test_tercer_escaneo_del_dia_corre_la_salida_al_nuevo_horario(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))->assertJsonPath('data.resultado', 'entrada_registrada');
        $this->viajarA('2026-10-05 17:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))->assertJsonPath('data.resultado', 'salida_registrada');

        // 3er escaneo, fuera de la ventana de antirrebote.
        $this->viajarA('2026-10-05 19:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'salida_registrada');

        $this->assertSame(3, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        // La salida "oficial" ahora es 19:00, no 17:00 — el motor no
        // distingue una salida corregida de una tardía, toma la última.
        $this->assertSame('19:00:00', $resultado->salida_at->format('H:i:s'));
    }

    public function test_cuarto_escaneo_del_dia_tambien_corre_la_salida(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        foreach (['08:00:00', '13:00:00', '13:45:00', '17:30:00'] as $hora) {
            $this->viajarA("2026-10-05 {$hora}");
            $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))->assertOk();
        }

        $this->assertSame(4, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $this->assertSame('08:00:00', $resultado->entrada_at->format('H:i:s'));
        $this->assertSame('17:30:00', $resultado->salida_at->format('H:i:s'));
    }

    public function test_entrada_por_huellero_salida_por_carnet_se_asocian_correctamente(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        // Entrada "por huellero" — se crea directo, sin pasar por el carnet.
        AsistenciaMarcacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'person_id' => $colaborador->numero_documento, 'marcado_at' => Carbon::parse('2026-10-05 08:00:00', 'America/Lima'),
            'origen' => 'transaction',
        ]);

        $this->viajarA('2026-10-05 17:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'salida_registrada');

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $this->assertSame('08:00:00', $resultado->entrada_at->format('H:i:s'));
        $this->assertSame('17:00:00', $resultado->salida_at->format('H:i:s'));
    }

    public function test_entrada_por_carnet_salida_por_huellero_se_asocian_correctamente(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->viajarA('2026-10-05 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertJsonPath('data.resultado', 'entrada_registrada');

        // Salida "por huellero" — el kiosco no se entera de este escaneo,
        // pero el motor sí debe cerrarla igual al reprocesar.
        AsistenciaMarcacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'person_id' => $colaborador->numero_documento, 'marcado_at' => Carbon::parse('2026-10-05 17:00:00', 'America/Lima'),
            'origen' => 'transaction',
        ]);

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $this->assertSame('08:00:00', $resultado->entrada_at->format('H:i:s'));
        $this->assertSame('17:00:00', $resultado->salida_at->format('H:i:s'));
    }

    public function test_entrada_antigua_sin_salida_no_es_secuestrada_por_escaneo_de_otro_dia(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        // Entrada lunes, SIN salida — queda como sesión abierta/incompleta.
        $this->viajarA('2026-10-05 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertJsonPath('data.resultado', 'entrada_registrada');

        // Escaneo NUEVO el miércoles — 2 días después, sin relación.
        $this->viajarA('2026-10-07 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'entrada_registrada'); // NO "salida" del lunes.

        $lunes = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-05'));
        $miercoles = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador->refresh(), Carbon::parse('2026-10-07'));
        $this->assertNotNull($lunes->entrada_at);
        $this->assertNull($lunes->salida_at, 'La entrada del lunes debe seguir incompleta, no "cerrada" por el escaneo del miércoles.');
        $this->assertNotNull($miercoles->entrada_at);
        $this->assertNull($miercoles->salida_at);
    }

    public function test_fallo_de_reproceso_no_pierde_la_marcacion_cruda(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);

        $this->partialMock(ProcesarAsistenciaDiaria::class, function ($mock) {
            $mock->shouldReceive('procesar')->andThrow(new \RuntimeException('fallo simulado de reproceso'));
        });

        $this->viajarA('2026-10-05 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($usuario))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'marcacion_registrada');

        // La marcación cruda sí quedó guardada pese al fallo del reproceso —
        // es la garantía central de separar ambos pasos en transacciones
        // distintas (ver RegistrarMarcacionCarnetService).
        $this->assertSame(1, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
    }

    // --- Seguridad de endpoints (Vigilancia vs. gestión de credenciales) --

    public function test_vigilancia_no_puede_generar_credenciales(): void
    {
        [$empresa] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->postJson("/api/colaboradores/{$colaborador->id}/credencial-carnet", [], $this->cabecera($vigilancia))
            ->assertStatus(403);
    }

    public function test_vigilancia_no_puede_revocar_credenciales(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->deleteJson("/api/colaboradores/{$colaborador->id}/credencial-carnet", ['motivo' => 'x'], $this->cabecera($vigilancia))
            ->assertStatus(403);
    }

    public function test_vigilancia_no_puede_ver_colaboradores(): void
    {
        [$empresa] = $this->escenario();
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->getJson('/api/asistencia/colaboradores', $this->cabecera($vigilancia))->assertStatus(403);
    }

    public function test_gestion_de_credenciales_exige_permiso_administrativo_y_empresa_activa(): void
    {
        [$empresaA, $usuarioA] = $this->escenario();
        $empresaB = Empresa::where('id', '!=', $empresaA->id)->firstOrFail();
        $colaboradorB = $this->crearColaboradorConHorario($empresaB);

        // $usuarioA tiene permiso "colaboradores.editar" (admin), pero su
        // empresa ACTIVA es A — no puede generar para un colaborador de B.
        $this->postJson("/api/colaboradores/{$colaboradorB->id}/credencial-carnet", [], $this->cabecera($usuarioA))
            ->assertStatus(404);
    }

    // --- No se rompe lo existente -----------------------------------------

    public function test_marcaciones_de_otros_origenes_no_se_ven_afectadas(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);

        $marcacion = AsistenciaMarcacion::create([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'person_id' => $colaborador->numero_documento,
            'marcado_at' => Carbon::parse('2026-10-05 08:00:00'),
            'origen' => 'transaction',
        ]);

        $this->assertDatabaseHas('asistencia_marcaciones', ['id' => $marcacion->id, 'origen' => 'transaction']);
    }

    // --- "Olvidó su carnet": búsqueda + confirmación visual + marcado manual

    public function test_vigilancia_puede_buscar_colaboradores_por_nombre(): void
    {
        [$empresa] = $this->escenario();
        $colaborador = $this->crearColaborador($empresa, ['nombres' => 'Mariana', 'apellidos' => 'Cabrera']);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $respuesta = $this->getJson('/api/control-acceso/colaboradores?buscar=Maria', $this->cabecera($vigilancia))
            ->assertOk();

        $respuesta->assertJsonFragment(['id' => $colaborador->id, 'nombre_mostrable' => 'Mariana Cabrera', 'cargo' => $colaborador->cargo]);
        // No expone documento ni otros datos — solo lo necesario para
        // reconocer a la persona junto con la foto.
        $respuesta->assertJsonMissingPath('data.0.numero_documento');
    }

    public function test_busqueda_de_colaboradores_no_revela_los_de_otra_empresa(): void
    {
        [$empresaA, $usuarioA] = $this->escenario();
        $empresaB = Empresa::where('id', '!=', $empresaA->id)->firstOrFail();
        $this->crearColaborador($empresaB, ['nombres' => 'ColaboradorSoloDeB', 'apellidos' => 'Apellido']);
        $vigilanciaA = $this->conPermisoControlAcceso($empresaA);

        $this->getJson('/api/control-acceso/colaboradores?buscar=ColaboradorSoloDeB', $this->cabecera($vigilanciaA))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_busqueda_de_colaboradores_exige_al_menos_2_caracteres(): void
    {
        [$empresa] = $this->escenario();
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->getJson('/api/control-acceso/colaboradores?buscar=M', $this->cabecera($vigilancia))
            ->assertStatus(422);
    }

    public function test_usuario_sin_permiso_no_puede_buscar_colaboradores(): void
    {
        [$empresa] = $this->escenario();
        $sinPermiso = $this->sinPermisos($empresa);

        $this->getJson('/api/control-acceso/colaboradores?buscar=Maria', $this->cabecera($sinPermiso))
            ->assertStatus(403);
    }

    public function test_foto_de_colaborador_de_otra_empresa_no_se_puede_ver(): void
    {
        [$empresaA] = $this->escenario();
        $empresaB = Empresa::where('id', '!=', $empresaA->id)->firstOrFail();
        $colaboradorB = $this->crearColaboradorConHorario($empresaB);
        $vigilanciaA = $this->conPermisoControlAcceso($empresaA);

        $this->getJson("/api/control-acceso/colaboradores/{$colaboradorB->id}/foto", $this->cabecera($vigilanciaA))
            ->assertStatus(404);
    }

    public function test_http_marcar_manual_registra_entrada(): void
    {
        [$empresa] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->viajarA('2026-10-05 08:00:00');

        $this->postJson("/api/control-acceso/colaboradores/{$colaborador->id}/marcar-manual", [], $this->cabecera($vigilancia))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'entrada_registrada')
            ->assertJsonPath('data.origen', AsistenciaMarcacion::ORIGEN_MANUAL_VIGILANCIA);

        $marcacion = AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->sole();
        $this->assertSame(AsistenciaMarcacion::ORIGEN_MANUAL_VIGILANCIA, $marcacion->origen);
    }

    public function test_marcar_manual_de_colaborador_de_otra_empresa_devuelve_404(): void
    {
        [$empresaA, $usuarioA] = $this->escenario();
        $empresaB = Empresa::where('id', '!=', $empresaA->id)->firstOrFail();
        $colaboradorB = $this->crearColaboradorConHorario($empresaB);
        $vigilanciaA = $this->conPermisoControlAcceso($empresaA);

        $this->postJson("/api/control-acceso/colaboradores/{$colaboradorB->id}/marcar-manual", [], $this->cabecera($vigilanciaA))
            ->assertStatus(404);
    }

    public function test_marcar_manual_de_colaborador_inactivo_es_rechazado(): void
    {
        [$empresa] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $colaborador->update(['activo' => false]);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->postJson("/api/control-acceso/colaboradores/{$colaborador->id}/marcar-manual", [], $this->cabecera($vigilancia))
            ->assertStatus(422)
            ->assertJsonValidationErrors('colaborador');
    }

    public function test_usuario_sin_permiso_no_puede_marcar_manual(): void
    {
        [$empresa] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        $sinPermiso = $this->sinPermisos($empresa);

        $this->postJson("/api/control-acceso/colaboradores/{$colaborador->id}/marcar-manual", [], $this->cabecera($sinPermiso))
            ->assertStatus(403);
    }

    public function test_antirebote_de_marcar_manual_comparte_ventana_con_el_escaneo_de_carnet(): void
    {
        [$empresa, $usuario] = $this->escenario();
        $colaborador = $this->crearColaboradorConHorario($empresa);
        ['token' => $token] = app(CarnetCredentialService::class)->generar($empresa, $colaborador, $usuario);
        $vigilancia = $this->conPermisoControlAcceso($empresa);

        $this->viajarA('2026-10-05 08:00:00');
        $this->postJson('/api/control-acceso/escanear', ['codigo' => $token], $this->cabecera($vigilancia))
            ->assertJsonPath('data.resultado', 'entrada_registrada');

        // 3 segundos después, alguien intenta registrar manualmente a la
        // MISMA persona — mismo canal lógico (kiosco), debe tratarse como
        // duplicado aunque el origen técnico sea distinto.
        $this->viajarA('2026-10-05 08:00:03');
        $this->postJson("/api/control-acceso/colaboradores/{$colaborador->id}/marcar-manual", [], $this->cabecera($vigilancia))
            ->assertOk()
            ->assertJsonPath('data.resultado', 'marcacion_duplicada');

        $this->assertSame(1, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
    }
}
