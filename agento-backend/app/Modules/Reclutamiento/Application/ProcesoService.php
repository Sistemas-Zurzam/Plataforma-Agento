<?php

namespace App\Modules\Reclutamiento\Application;

use App\Models\User;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\FlujoProceso;
use App\Modules\Reclutamiento\Domain\PerfilSugerido;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\Actividad;
use App\Modules\Reclutamiento\Models\PerfilProceso;
use App\Modules\Reclutamiento\Models\Plaza;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcesoService
{
    /** Pesos iniciales del puntaje final; se configuran por proceso en el perfil. */
    private const PESOS_INICIALES = ['ats' => 40, 'psicometria' => 30, 'entrevistas' => 30];

    public function __construct(
        private readonly RegistroActividad $actividad,
        private readonly PostulacionService $postulaciones,
    ) {}

    public function listar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return ProcesoSeleccion::query()
            ->where('empresa_id', $alcance->empresaId)
            ->when(! $alcance->accesoTotal(), fn ($q) => $q->whereIn('area_id', $alcance->areaIds))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['reclutador_id'] ?? null, fn ($q, $id) => $q->where('reclutador_user_id', $id))
            ->when($filtros['busqueda'] ?? null, fn ($q, $texto) => $q->where(fn ($sub) => $sub
                ->where('cargo', 'like', '%'.$texto.'%')->orWhere('codigo', 'like', '%'.$texto.'%')))
            ->with(['area:id,nombre', 'reclutador:id,name'])
            ->withCount([
                'plazas as plazas_total' => fn ($q) => $q->where('estado', '!=', Plaza::CANCELADA),
                'plazas as plazas_cubiertas' => fn ($q) => $q->where('estado', Plaza::CUBIERTA),
                'postulaciones as postulaciones_activas' => fn ($q) => $q->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA]),
            ])
            ->latest('id')
            ->paginate($perPage);
    }

    public function autorizarLectura(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso): void
    {
        if ($proceso->empresa_id !== $alcance->empresaId) {
            throw new AuthorizationException('El proceso no pertenece a la empresa activa.');
        }
        $alcance->exigirArea($proceso->area_id);
    }

    public function detalle(ProcesoSeleccion $proceso): ProcesoSeleccion
    {
        return $proceso->load([
            'area:id,nombre', 'reclutador:id,name', 'solicitud:id,motivo,urgente,solicitante_user_id,plazas',
            'perfilVigente.requisitos', 'perfilVigente.competencias', 'plazas.colaborador:id,nombres,apellidos,legajo',
            'dimensionesPrioritarias',
            'plazas.postulacion.candidato:id,nombres,apellido_paterno,apellido_materno',
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function candidatosPorEtapa(ProcesoSeleccion $proceso): array
    {
        $conteos = Postulacion::where('proceso_id', $proceso->id)
            ->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA])
            ->selectRaw('etapa, COUNT(*) as total')
            ->groupBy('etapa')
            ->pluck('total', 'etapa');

        return collect(FlujoPostulacion::ETAPAS)->mapWithKeys(fn ($etapa) => [$etapa => (int) ($conteos[$etapa] ?? 0)])->all();
    }

    /**
     * Llamado solo desde SolicitudService::decidir dentro de su transacción.
     */
    public function crearDesdeSolicitud(SolicitudPersonal $solicitud, ?int $reclutadorId, int $usuarioId): ProcesoSeleccion
    {
        if (ProcesoSeleccion::where('solicitud_id', $solicitud->id)->exists()) {
            throw new TransicionInvalidaException('La solicitud ya tiene un proceso de selección.');
        }

        if ($reclutadorId !== null) {
            $this->validarUsuarioDeEmpresa($reclutadorId, $solicitud->empresa_id, 'reclutador_user_id');
        }

        $proceso = ProcesoSeleccion::create([
            'empresa_id' => $solicitud->empresa_id,
            'solicitud_id' => $solicitud->id,
            // El id de la solicitud es único global: el código también lo es,
            // lo que permite resolver /empleos/:codigo sin ambigüedad.
            'codigo' => 'SEL-'.now()->format('Y').'-'.str_pad((string) $solicitud->id, 5, '0', STR_PAD_LEFT),
            'area_id' => $solicitud->area_id,
            'reclutador_user_id' => $reclutadorId ?? $usuarioId,
            'cargo' => $solicitud->cargo,
            'descripcion' => $this->descripcionDelAviso($solicitud),
            'modalidad' => $solicitud->modalidad,
            'tipo_contrato' => $solicitud->tipo_contrato,
            'salario_min' => $solicitud->salario_min,
            'salario_max' => $solicitud->salario_max,
            'moneda' => $solicitud->moneda,
            'fecha_requerida' => $solicitud->fecha_requerida,
            'estado' => ProcesoSeleccion::BORRADOR,
        ]);

        $this->crearVersionPerfil($proceso, [
            'umbral_ats' => (int) config('reclutamiento.ats.umbral_por_defecto', 60),
            'pesos' => self::PESOS_INICIALES,
            'requisitos' => PerfilSugerido::requisitos($solicitud->requisitos),
            'competencias' => PerfilSugerido::competencias($solicitud->habilidades()->get(['nombre', 'nivel'])->map->only(['nombre', 'nivel'])),
        ], $usuarioId);

        for ($numero = 1; $numero <= $solicitud->plazas; $numero++) {
            Plaza::create([
                'empresa_id' => $solicitud->empresa_id,
                'proceso_id' => $proceso->id,
                'numero' => $numero,
                'estado' => Plaza::DISPONIBLE,
            ]);
        }

        $this->actividad->registrar($proceso->empresa_id, $proceso, 'proceso_creado', $usuarioId, null, [
            'plazas' => $solicitud->plazas, 'solicitud_id' => $solicitud->id,
        ]);

        return $proceso;
    }

    public function actualizar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $datos): ProcesoSeleccion
    {
        $this->exigirNoFinalizado($proceso);
        if (isset($datos['reclutador_user_id'])) {
            $this->validarUsuarioDeEmpresa((int) $datos['reclutador_user_id'], $proceso->empresa_id, 'reclutador_user_id');
        }

        $antes = $proceso->getAttributes();
        $proceso->update($datos);
        $this->actividad->registrar($proceso->empresa_id, $proceso, 'proceso_editado', $alcance->usuario->id, null,
            RegistroActividad::diferencias($antes, $proceso));

        return $this->detalle($proceso->refresh());
    }

    /**
     * Cada cambio de perfil crea una versión nueva; las evaluaciones ya
     * registradas conservan la versión con la que se calcularon.
     */
    /**
     * Evaluación psicométrica del proceso: la prueba (por familia, siempre
     * su última versión publicada) que se envía automáticamente al postular,
     * el nivel mínimo y las dimensiones que deben alcanzarlo. Sin prueba, no
     * se envía nada.
     */
    public function configurarEvaluacion(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $datos): ProcesoSeleccion
    {
        $this->exigirNoFinalizado($proceso);
        $familia = $datos['prueba_familia'] ?? null;
        $codigos = collect($datos['dimensiones'] ?? [])->map(fn ($c) => strtoupper((string) $c))->unique()->values();

        if ($familia) {
            $proceso->prueba_familia = $familia;
            $prueba = $proceso->pruebaVigente();
            if (! $prueba) {
                throw ValidationException::withMessages(['prueba_familia' => 'La prueba no existe o no tiene una versión publicada para esta empresa.']);
            }
            $validos = $prueba->dimensiones()->where('es_validez', false)->whereNotNull('codigo')->pluck('codigo');
            $invalidos = $codigos->diff($validos);
            if ($invalidos->isNotEmpty()) {
                throw ValidationException::withMessages(['dimensiones' => 'Dimensiones que no existen en la prueba: '.$invalidos->implode(', ').'.']);
            }
            if ($codigos->isNotEmpty() && empty($datos['nivel_minimo'])) {
                throw ValidationException::withMessages(['nivel_minimo' => 'Indica el nivel mínimo que deben alcanzar las dimensiones prioritarias.']);
            }
        }

        DB::transaction(function () use ($alcance, $proceso, $familia, $codigos, $datos) {
            $proceso->update([
                'prueba_familia' => $familia,
                'prueba_nivel_minimo' => $familia && $codigos->isNotEmpty() ? $datos['nivel_minimo'] : null,
            ]);
            $proceso->dimensionesPrioritarias()->delete();
            if ($familia) {
                $codigos->each(fn ($codigo) => $proceso->dimensionesPrioritarias()->create(['codigo' => $codigo]));
            }
            $this->actividad->registrar($proceso->empresa_id, $proceso, 'evaluacion_configurada', $alcance->usuario->id, null, [
                'prueba_familia' => $familia, 'nivel_minimo' => $proceso->prueba_nivel_minimo, 'dimensiones' => $codigos->all(),
            ]);
        });

        return $this->detalle($proceso->refresh());
    }

    /**
     * Prueba de conocimientos del proceso (por familia: su última versión
     * publicada), cuándo se envía (al postular o al pasar el filtro de CV) y
     * el puntaje mínimo (0–100) que marca a quien no lo alcanza.
     */
    public function configurarConocimientos(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $datos): ProcesoSeleccion
    {
        $this->exigirNoFinalizado($proceso);
        $familia = $datos['prueba_familia'] ?? null;

        if ($familia) {
            $prueba = $proceso->pruebaVigente($familia);
            if (! $prueba) {
                throw ValidationException::withMessages(['prueba_familia' => 'La prueba no existe o no tiene una versión publicada para esta empresa.']);
            }
            if ($prueba->tipo !== 'conocimientos') {
                throw ValidationException::withMessages(['prueba_familia' => 'Elige una prueba de conocimientos.']);
            }
        }

        $proceso->update([
            'conocimientos_familia' => $familia,
            'conocimientos_momento' => $familia ? ($datos['momento'] ?? EnvioAutomaticoPruebas::AL_PASAR_FILTRO_CV) : null,
            'conocimientos_puntaje_minimo' => $familia ? ($datos['puntaje_minimo'] ?? null) : null,
        ]);
        $this->actividad->registrar($proceso->empresa_id, $proceso, 'evaluacion_conocimientos_configurada', $alcance->usuario->id, null, [
            'prueba_familia' => $familia, 'momento' => $proceso->conocimientos_momento, 'puntaje_minimo' => $proceso->conocimientos_puntaje_minimo,
        ]);

        return $this->detalle($proceso->refresh());
    }

    public function guardarPerfil(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $datos): ProcesoSeleccion
    {
        $this->exigirNoFinalizado($proceso);

        DB::transaction(function () use ($alcance, $proceso, $datos) {
            $perfil = $this->crearVersionPerfil($proceso, $datos, $alcance->usuario->id);
            $this->actividad->registrar($proceso->empresa_id, $proceso, 'perfil_versionado', $alcance->usuario->id, $datos['motivo'] ?? null, [
                'version' => $perfil->version,
            ]);
        });

        return $this->detalle($proceso->refresh());
    }

    /**
     * Publicar: abre el proceso si estaba en borrador y registra la(s)
     * publicación(es). La interna puede registrarse antes que la externa; no
     * se impone ningún plazo de exclusividad.
     */
    public function publicar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, bool $interna, bool $externa): ProcesoSeleccion
    {
        if (! $interna && ! $externa) {
            throw ValidationException::withMessages(['publicacion' => 'Indica al menos un canal de publicación.']);
        }

        DB::transaction(function () use ($alcance, $proceso, $interna, $externa) {
            $bloqueado = ProcesoSeleccion::whereKey($proceso->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado === ProcesoSeleccion::BORRADOR) {
                $this->validarPerfilPublicable($bloqueado);
                $bloqueado->estado = FlujoProceso::aplicar($bloqueado->estado, 'abrir');
                $bloqueado->fecha_inicio ??= now()->toDateString();
            } elseif (! in_array($bloqueado->estado, [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA], true)) {
                throw new TransicionInvalidaException('Solo se publican procesos en borrador, abiertos o en pausa.');
            }

            if ($interna && ! $bloqueado->publicacion_interna) {
                $bloqueado->publicacion_interna = true;
                $bloqueado->publicada_interna_at = now();
            }
            if ($externa && ! $bloqueado->publicacion_externa) {
                $bloqueado->publicacion_externa = true;
                $bloqueado->publicada_externa_at = now();
            }
            $bloqueado->retirada_at = null;
            $bloqueado->save();

            $this->actividad->registrar($bloqueado->empresa_id, $bloqueado, 'proceso_publicado', $alcance->usuario->id, null, [
                'interna' => $bloqueado->publicacion_interna, 'externa' => $bloqueado->publicacion_externa,
            ]);
        });

        return $this->detalle($proceso->refresh());
    }

    public function retirarPublicacion(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, string $motivo): ProcesoSeleccion
    {
        $proceso->update(['publicacion_externa' => false, 'publicacion_interna' => false, 'retirada_at' => now()]);
        $this->actividad->registrar($proceso->empresa_id, $proceso, 'publicacion_retirada', $alcance->usuario->id, $motivo);

        return $this->detalle($proceso->refresh());
    }

    /**
     * @param  'pausar'|'reanudar'|'cerrar'  $accion
     */
    public function cambiarEstado(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, string $accion, ?string $motivo, ?string $mensajePublico): ProcesoSeleccion
    {
        DB::transaction(function () use ($alcance, $proceso, $accion, $motivo, $mensajePublico) {
            $bloqueado = ProcesoSeleccion::whereKey($proceso->id)->lockForUpdate()->firstOrFail();
            $estado = FlujoProceso::aplicar($bloqueado->estado, $accion, $motivo);

            $bloqueado->update([
                'estado' => $estado,
                'motivo_estado' => $accion === 'reanudar' ? null : $motivo,
                'mensaje_publico' => match ($accion) {
                    'pausar' => $mensajePublico ?: 'Este proceso está temporalmente en pausa. Vuelve a consultarlo más adelante.',
                    'cerrar' => $mensajePublico ?: 'Este proceso ya no recibe postulaciones.',
                    default => null,
                },
                'retirada_at' => $accion === 'cerrar' ? now() : $bloqueado->retirada_at,
            ]);

            $this->actividad->registrar($bloqueado->empresa_id, $bloqueado, "proceso_{$estado}", $alcance->usuario->id, $motivo);

            // Cerrado es final: los candidatos vigentes ya no pueden avanzar y
            // deben verlo así en "Mis postulaciones".
            if ($accion === 'cerrar') {
                $this->postulaciones->finalizarPorCierre($bloqueado, $alcance->usuario->id, $mensajePublico);
            }
        });

        return $this->detalle($proceso->refresh());
    }

    /**
     * Se ejecuta dentro de la transacción de cancelación de la solicitud.
     * Las plazas cubiertas (contrataciones existentes) no se tocan.
     */
    public function cancelarPorSolicitud(ProcesoSeleccion $proceso, string $motivo, int $usuarioId): void
    {
        $bloqueado = ProcesoSeleccion::whereKey($proceso->id)->lockForUpdate()->firstOrFail();
        $estado = FlujoProceso::aplicar($bloqueado->estado, 'cancelar', $motivo);

        $bloqueado->update([
            'estado' => $estado,
            'motivo_estado' => $motivo,
            'mensaje_publico' => 'Este proceso fue cancelado.',
            'publicacion_interna' => false,
            'publicacion_externa' => false,
            'retirada_at' => now(),
        ]);

        $canceladas = Plaza::where('proceso_id', $bloqueado->id)
            ->where('estado', Plaza::DISPONIBLE)
            ->update(['estado' => Plaza::CANCELADA, 'cancelada_at' => now(), 'motivo_cancelacion' => $motivo]);

        $this->actividad->registrar($bloqueado->empresa_id, $bloqueado, 'proceso_cancelado', $usuarioId, $motivo, [
            'plazas_canceladas' => $canceladas,
        ]);
    }

    /**
     * Cuando todas las plazas no canceladas están cubiertas, el proceso pasa a
     * "cubierto" y su publicación deja de recibir postulaciones.
     */
    public function verificarCobertura(ProcesoSeleccion $proceso, ?int $usuarioId): void
    {
        $plazas = Plaza::where('proceso_id', $proceso->id)->where('estado', '!=', Plaza::CANCELADA)->get(['estado']);

        if ($plazas->isNotEmpty() && $plazas->every(fn (Plaza $p) => $p->estado === Plaza::CUBIERTA)
            && in_array($proceso->estado, [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA], true)) {
            $proceso->update([
                'estado' => FlujoProceso::aplicar($proceso->estado, 'cubrir'),
                'cubierto_at' => now(),
                'mensaje_publico' => 'Todas las vacantes de este proceso fueron cubiertas.',
            ]);
            $this->actividad->registrar($proceso->empresa_id, $proceso, 'proceso_cubierto', $usuarioId);
        }
    }

    public function actividadDe(ProcesoSeleccion $proceso): Collection
    {
        return Actividad::where('empresa_id', $proceso->empresa_id)
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('entidad_tipo', 'ProcesoSeleccion')->where('entidad_id', $proceso->id))
                ->orWhere(fn ($s) => $s->where('entidad_tipo', 'SolicitudPersonal')->where('entidad_id', $proceso->solicitud_id)))
            ->with('actor:id,name')
            ->latest('id')
            ->limit(200)
            ->get();
    }

    private function crearVersionPerfil(ProcesoSeleccion $proceso, array $datos, ?int $usuarioId): PerfilProceso
    {
        $pesos = $datos['pesos'];
        if (array_sum($pesos) !== 100) {
            throw ValidationException::withMessages(['pesos' => 'Los pesos del puntaje final deben sumar 100.']);
        }

        $competencias = $datos['competencias'] ?? [];
        if ($competencias !== [] && array_sum(array_map(fn ($c) => (int) $c['peso'], $competencias)) !== 100) {
            throw ValidationException::withMessages(['competencias' => 'Los pesos de las competencias deben sumar 100.']);
        }

        $version = (int) PerfilProceso::where('proceso_id', $proceso->id)->max('version') + 1;
        $perfil = PerfilProceso::create([
            'proceso_id' => $proceso->id,
            'version' => $version,
            'umbral_ats' => $datos['umbral_ats'],
            'peso_ats' => $pesos['ats'],
            'peso_psicometria' => $pesos['psicometria'],
            'peso_entrevistas' => $pesos['entrevistas'],
            'creado_por' => $usuarioId,
        ]);

        foreach (array_values($datos['requisitos'] ?? []) as $orden => $requisito) {
            $perfil->requisitos()->create(['tipo' => $requisito['tipo'], 'descripcion' => trim($requisito['descripcion']), 'orden' => $orden]);
        }
        foreach ($competencias as $competencia) {
            $perfil->competencias()->create([
                'nombre' => trim($competencia['nombre']),
                'nivel' => (int) $competencia['nivel'],
                'peso' => (int) $competencia['peso'],
            ]);
        }

        return $perfil;
    }

    /**
     * Texto inicial del aviso con las secciones que la empresa describió
     * (descripción, funciones y horario). Los requisitos no van aquí: pasan
     * al perfil, que el portal ya lista y pregunta al postular. RR. HH. puede
     * editarlo antes de publicar; la remuneración se publica aparte.
     */
    private function descripcionDelAviso(SolicitudPersonal $solicitud): string
    {
        $secciones = [trim((string) $solicitud->descripcion)];
        foreach (['Funciones principales' => $solicitud->actividades, 'Horario laboral' => $solicitud->horario] as $titulo => $texto) {
            if (trim((string) $texto) !== '') {
                $secciones[] = $titulo."\n".trim((string) $texto);
            }
        }

        return implode("\n\n", $secciones);
    }

    /**
     * Perfil propuesto desde la solicitud (requisitos y habilidades que
     * describió la empresa), para cargarlo en el formulario del perfil.
     *
     * @return array{requisitos: array<int, array{tipo: string, descripcion: string}>, competencias: array<int, array{nombre: string, nivel: int, peso: int}>}|null
     */
    public function perfilSugerido(ProcesoSeleccion $proceso): ?array
    {
        $solicitud = $proceso->solicitud()->with('habilidades')->first(['id', 'requisitos']);
        if (! $solicitud) {
            return null;
        }

        return [
            'requisitos' => PerfilSugerido::requisitos($solicitud->requisitos),
            'competencias' => PerfilSugerido::competencias($solicitud->habilidades->map->only(['nombre', 'nivel'])),
        ];
    }

    private function validarPerfilPublicable(ProcesoSeleccion $proceso): void
    {
        $perfil = $proceso->perfilVigente()->withCount('competencias')->first();
        if (! $perfil || $perfil->competencias_count === 0) {
            throw ValidationException::withMessages(['perfil' => 'Define al menos una competencia en el perfil antes de publicar.']);
        }
    }

    private function exigirNoFinalizado(ProcesoSeleccion $proceso): void
    {
        if (in_array($proceso->estado, [ProcesoSeleccion::CERRADO, ProcesoSeleccion::CANCELADO], true)) {
            throw new TransicionInvalidaException('El proceso está finalizado y no admite cambios.');
        }
    }

    private function validarUsuarioDeEmpresa(int $usuarioId, int $empresaId, string $campo): void
    {
        $usuario = User::find($usuarioId);
        $pertenece = $usuario && ($usuario->esAdministradorGlobal() || $usuario->empresas()->where('empresas.id', $empresaId)->exists());

        if (! $pertenece) {
            throw ValidationException::withMessages([$campo => 'El usuario indicado no pertenece a la empresa.']);
        }
    }
}
