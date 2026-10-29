<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Models\AccesoCandidato;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Escenarios de Selección y Reclutamiento para pruebas de integración.
 */
trait CreaEscenarioSeleccion
{
    protected function prepararSeleccion(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);

        // ColaboradorService::siguienteLegajo() usa SUBSTRING_INDEX (MySQL);
        // en SQLite de pruebas se registra una función equivalente.
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($texto, $delimitador, $cantidad) {
                $partes = explode($delimitador, (string) $texto);

                return $cantidad < 0
                    ? implode($delimitador, array_slice($partes, $cantidad))
                    : implode($delimitador, array_slice($partes, 0, $cantidad));
            }, 3);
        }
    }

    /**
     * RefreshDatabase reutiliza la conexión SQLite en memoria entre pruebas y
     * SUBSTRING_INDEX quedaría registrada para las siguientes suites (p. ej.
     * cambiaría lo que crea ColaboradorSeeder). Se descarta esa conexión para
     * que la próxima prueba use una base nueva.
     */
    protected function tearDownCreaEscenarioSeleccion(): void
    {
        RefreshDatabaseState::$inMemoryConnections = [];
        RefreshDatabaseState::$migrated = false;
    }

    protected function crearEmpresa(string $nombre = 'Empresa'): Empresa
    {
        return Empresa::factory()->create(['nombre_comercial' => $nombre.' '.Str::random(4), 'activa' => true]);
    }

    protected function crearArea(Empresa $empresa, string $nombre = 'Almacén', ?User $responsable = null): Area
    {
        return Area::withoutGlobalScopes()->create([
            'empresa_id' => $empresa->id, 'nombre' => $nombre.' '.Str::random(4), 'responsable_user_id' => $responsable?->id,
        ]);
    }

    protected function usuarioCon(Empresa $empresa, string $rol, ?Area $area = null): User
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id, 'area_id' => $area?->id, 'activo' => true, 'token_version' => 0]);
        $usuario->empresas()->updateExistingPivot($empresa->id, ['role_id' => Role::where('clave', $rol)->value('id')]);

        return $usuario->fresh();
    }

    protected function cabeceras(User $usuario): array
    {
        // En pruebas la app (y el guard JWT) persiste entre requests: sin
        // esto, el usuario del request anterior quedaría en caché.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
    }

    /**
     * @return array<string, mixed>
     */
    protected function datosSolicitud(Area $area, array $extra = []): array
    {
        return [
            'area_id' => $area->id,
            'cargo' => 'Operario de almacén',
            'motivo' => 'nuevo_puesto',
            'plazas' => 2,
            'salario_min' => 1200,
            'salario_max' => 1500,
            'tipo_contrato' => 'plazo_fijo',
            'modalidad' => 'presencial',
            'fecha_requerida' => now()->addMonth()->toDateString(),
            'descripcion' => str_repeat('Operario para recepción y despacho de mercadería. ', 2),
            'habilidades' => [['nombre' => 'Montacargas', 'nivel' => 3], ['nombre' => 'Inventarios', 'nivel' => 2]],
            ...$extra,
        ];
    }

    /**
     * Solicitud creada, enviada y aceptada por RR. HH.; perfil con un
     * excluyente y proceso publicado externamente.
     */
    protected function procesoPublicado(User $rys, Area $area, int $plazas = 2, bool $externa = true): ProcesoSeleccion
    {
        $headers = $this->cabeceras($rys);
        $solicitud = $this->withHeaders($headers)
            ->postJson('/api/seleccion/solicitudes', $this->datosSolicitud($area, ['plazas' => $plazas, 'enviar' => true]))
            ->assertCreated()->json('data');
        $this->withHeaders($headers)->postJson("/api/seleccion/solicitudes/{$solicitud['id']}/decision", ['accion' => 'aceptar'])->assertOk();

        $proceso = ProcesoSeleccion::withoutGlobalScopes()->where('solicitud_id', $solicitud['id'])->firstOrFail();
        $this->withHeaders($headers)->putJson("/api/seleccion/procesos/{$proceso->id}/perfil", [
            'umbral_ats' => 60,
            'pesos' => ['ats' => 40, 'psicometria' => 30, 'entrevistas' => 30],
            'requisitos' => [['tipo' => 'excluyente', 'descripcion' => 'Secundaria completa']],
            'competencias' => [['nombre' => 'Montacargas', 'nivel' => 3, 'peso' => 60], ['nombre' => 'Inventarios', 'nivel' => 2, 'peso' => 40]],
        ])->assertOk();
        $this->withHeaders($headers)->postJson("/api/seleccion/procesos/{$proceso->id}/publicar", ['interna' => ! $externa, 'externa' => $externa])->assertOk();

        return $proceso->fresh();
    }

    protected function tokenCandidato(string $email): string
    {
        $token = Str::random(64);
        AccesoCandidato::create(['email' => $email, 'token_hash' => hash('sha256', $token), 'expira_at' => now()->addHour()]);

        return $token;
    }

    protected function cvValido(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /**
     * @return array<string, mixed>
     */
    protected function datosPostulacion(ProcesoSeleccion $proceso, array $extra = []): array
    {
        $excluyentes = $proceso->perfilVigente()->first()->requisitos()->where('tipo', 'excluyente')->pluck('id');

        return [
            'tipo_documento' => 'dni',
            'numero_documento' => '4'.random_int(1000000, 9999999),
            'nombres' => 'Rosa',
            'apellido_paterno' => 'Quispe',
            'apellido_materno' => 'Mamani',
            'celular' => '987654321',
            'ubicacion' => 'Lima',
            'experiencias' => json_encode([['titulo' => 'Almacenera', 'institucion' => 'Logística SAC']]),
            'estudios' => json_encode([['titulo' => 'Secundaria', 'institucion' => 'IE 123']]),
            'habilidades' => json_encode([['nombre' => 'Montacargas', 'nivel' => 3], ['nombre' => 'Inventarios', 'nivel' => 2]]),
            'requisitos' => json_encode($excluyentes->mapWithKeys(fn ($id) => [$id => true])->all()),
            'cv' => $this->cvValido(),
            'consentimiento' => '1',
            'consentimiento_texto' => 'Autorizo el tratamiento de mis datos personales.',
            ...$extra,
        ];
    }

    protected function postular(ProcesoSeleccion $proceso, string $email, array $extra = [])
    {
        return $this->withHeaders(['X-Candidato-Token' => $this->tokenCandidato($email)])
            ->post("/api/publico/empleos/{$proceso->codigo}/postulaciones", $this->datosPostulacion($proceso, $extra), ['Accept' => 'application/json']);
    }

    /**
     * Postulación creada directamente (sin pasar por el portal) en la etapa y
     * estado indicados.
     */
    protected function postulacionEn(ProcesoSeleccion $proceso, string $etapa = 'postulacion', string $estado = FlujoPostulacion::ACTIVA, array $candidato = []): Postulacion
    {
        $registro = Candidato::withoutGlobalScopes()->create([
            'empresa_id' => $proceso->empresa_id,
            'email' => Str::random(8).'@correo.test',
            'tipo_documento' => 'dni',
            'numero_documento' => (string) random_int(10000000, 99999999),
            'nombres' => 'Carlos',
            'apellido_paterno' => 'Pérez',
            'celular' => '999111222',
            'ultima_postulacion_at' => now(),
            ...$candidato,
        ]);

        return Postulacion::withoutGlobalScopes()->create([
            'empresa_id' => $proceso->empresa_id,
            'proceso_id' => $proceso->id,
            'candidato_id' => $registro->id,
            'codigo_seguimiento' => 'P'.strtoupper(Str::random(9)),
            'fuente' => 'portal_publico',
            'etapa' => $etapa,
            'estado' => $estado,
            'etapa_desde_at' => now(),
            'perfil_version' => 1,
            'perfil_snapshot' => ['nombres' => 'Carlos'],
        ]);
    }

    /**
     * @return array{sede: Sede, horario: Horario}
     */
    protected function catalogosContratacion(Empresa $empresa): array
    {
        return [
            'sede' => Sede::create(['empresa_id' => $empresa->id, 'codigo' => 'S'.substr(sha1(uniqid('', true)), 0, 8), 'nombre' => 'Sede Central', 'activa' => true]),
            'horario' => Horario::create(['empresa_id' => $empresa->id, 'nombre' => 'Diurno', 'tipo_turno' => 'normal', 'vigencia_desde' => today()->subYear(), 'activo' => true]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function datosContratacion(array $catalogos, array $extra = []): array
    {
        return [
            'sede_id' => $catalogos['sede']->id,
            'horario_id' => $catalogos['horario']->id,
            'celular_referencia' => '988777666',
            'tipo_trabajador' => 'trabajador',
            'categoria_trabajador' => 'obrero',
            'regimen_laboral' => 'General',
            'fecha_ingreso' => now()->addWeek()->toDateString(),
            'salario' => 1300,
            ...$extra,
        ];
    }
}
