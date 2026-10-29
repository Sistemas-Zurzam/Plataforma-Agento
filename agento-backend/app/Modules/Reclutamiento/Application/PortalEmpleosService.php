<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Infrastructure\AlmacenDocumentosCandidato;
use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Jobs\EvaluarPostulacionJob;
use App\Modules\Reclutamiento\Models\AccesoCandidato;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Consentimiento;
use App\Modules\Reclutamiento\Models\Invitacion;
use App\Modules\Reclutamiento\Models\PerfilProceso;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal público de empleos. Sin usuario autenticado: la empresa se resuelve
 * SIEMPRE desde la publicación (proceso) y la postulación queda vinculada a
 * esa empresa. Ninguna consulta aquí acepta empresa_id del cliente.
 */
class PortalEmpleosService
{
    public function __construct(
        private readonly AlmacenDocumentosCandidato $documentos,
        private readonly PostulacionService $postulaciones,
        private readonly CorreoReclutamiento $correo,
        private readonly RegistroActividad $actividad,
    ) {}

    public function listar(array $filtros, int $perPage): LengthAwarePaginator
    {
        return ProcesoSeleccion::query()
            ->publicoVisible()
            ->when($filtros['cargo'] ?? null, fn (Builder $q, $cargo) => $q->where('cargo', 'like', "%{$cargo}%"))
            ->when($filtros['ubicacion'] ?? null, fn (Builder $q, $ubicacion) => $q->where('ubicacion', 'like', "%{$ubicacion}%"))
            ->when($filtros['modalidad'] ?? null, fn (Builder $q, $modalidad) => $q->where('modalidad', $modalidad))
            ->with(['empresa:id,nombre_comercial,logo_path'])
            ->orderByDesc('publicada_externa_at')
            ->paginate($perPage);
    }

    /**
     * Resuelve una publicación por código. Un proceso solo interno requiere
     * una invitación vigente emitida por RR. HH. para ese proceso; sin ella
     * responde 404 (no se revela que existe).
     */
    public function resolverPublicacion(string $codigo, ?string $tokenInvitacion): array
    {
        $proceso = ProcesoSeleccion::query()
            ->where('codigo', $codigo)
            ->whereIn('estado', [ProcesoSeleccion::ABIERTO, ProcesoSeleccion::EN_PAUSA, ProcesoSeleccion::CUBIERTO, ProcesoSeleccion::CERRADO])
            ->with(['empresa:id,nombre_comercial,logo_path', 'perfilVigente.requisitos', 'perfilVigente.competencias'])
            ->first();

        if (! $proceso) {
            throw new NotFoundHttpException;
        }

        $invitacion = $tokenInvitacion ? $this->invitacionVigente($proceso, $tokenInvitacion) : null;

        // Sin publicación externa, solo una invitación vigente para ESTE
        // proceso da acceso: una vacante interna nunca se expone al público.
        if (! $proceso->publicacion_externa && ! $invitacion) {
            throw new NotFoundHttpException;
        }

        return ['proceso' => $proceso, 'invitacion' => $invitacion];
    }

    /**
     * Datos previos del candidato para prellenar el formulario (el más
     * reciente entre sus registros). Solo para el correo verificado.
     */
    public function perfilPrevio(AccesoCandidato $acceso): ?Candidato
    {
        return Candidato::where('email', $acceso->email)
            ->whereNull('anonimizado_at')
            ->with(['antecedentes', 'habilidades'])
            ->latest('updated_at')
            ->first();
    }

    /**
     * Postular. Una persona (correo verificado) no puede postular dos veces al
     * mismo proceso (unique proceso_id + candidato_id); sí a varios procesos.
     */
    public function postular(string $codigo, ?string $tokenInvitacion, AccesoCandidato $acceso, array $datos, UploadedFile $cv, ?string $ip): Postulacion
    {
        ['proceso' => $proceso, 'invitacion' => $invitacion] = $this->resolverPublicacion($codigo, $tokenInvitacion);

        if (! $proceso->aceptaPostulaciones()) {
            throw ValidationException::withMessages(['proceso' => $proceso->mensaje_publico ?: 'Este proceso no está recibiendo postulaciones.']);
        }

        if ($invitacion && $invitacion->email !== $acceso->email) {
            throw ValidationException::withMessages(['invitacion' => 'La invitación corresponde a otro correo.']);
        }

        $perfil = $proceso->perfilVigente;
        $this->validarDeclaraciones($perfil, $datos['requisitos'] ?? []);

        $archivoGuardado = null;

        try {
            $postulacion = DB::transaction(function () use ($proceso, $invitacion, $acceso, $datos, $cv, $ip, $perfil, &$archivoGuardado) {
                $candidato = Candidato::firstOrNew(['empresa_id' => $proceso->empresa_id, 'email' => $acceso->email]);
                $candidato->fill([
                    'email_verificado_at' => now(),
                    'tipo_documento' => $datos['tipo_documento'],
                    'numero_documento' => $datos['numero_documento'],
                    'nombres' => $datos['nombres'],
                    'apellido_paterno' => $datos['apellido_paterno'],
                    'apellido_materno' => $datos['apellido_materno'] ?? null,
                    'celular' => $datos['celular'],
                    'ubicacion' => $datos['ubicacion'] ?? null,
                    'resumen' => $datos['resumen'] ?? null,
                    'ultima_postulacion_at' => now(),
                    'anonimizado_at' => null,
                ])->save();

                if (Postulacion::where('proceso_id', $proceso->id)->where('candidato_id', $candidato->id)->exists()) {
                    throw ValidationException::withMessages(['proceso' => 'Ya postulaste a este proceso. Puedes revisar su estado en "Mis postulaciones".']);
                }

                $this->reemplazarPerfil($candidato, $datos);

                $documento = $this->documentos->guardarCv($proceso->empresa_id, $candidato->id, $cv);
                $archivoGuardado = $documento;

                Consentimiento::create([
                    'empresa_id' => $proceso->empresa_id,
                    'candidato_id' => $candidato->id,
                    'version' => config('reclutamiento.consentimiento.version'),
                    'texto_hash' => hash('sha256', (string) $datos['consentimiento_texto']),
                    'aceptado_at' => now(),
                    'ip' => $ip,
                ]);

                $postulacion = Postulacion::create([
                    'empresa_id' => $proceso->empresa_id,
                    'proceso_id' => $proceso->id,
                    'candidato_id' => $candidato->id,
                    'codigo_seguimiento' => $this->codigoSeguimiento(),
                    'fuente' => $invitacion ? ($invitacion->tipo === Invitacion::INTERNA ? 'portal_interno' : 'invitacion') : 'portal_publico',
                    'etapa' => 'postulacion',
                    'estado' => FlujoPostulacion::ACTIVA,
                    'etapa_desde_at' => now(),
                    'cv_documento_id' => $documento->id,
                    'perfil_snapshot' => $this->snapshot($candidato->refresh()->load(['antecedentes', 'habilidades']), $datos),
                    'perfil_version' => $perfil->version,
                ]);

                $this->postulaciones->registrarMovimiento($postulacion, null, null, null, null, 'candidato');
                $this->actividad->registrar($proceso->empresa_id, $postulacion, 'postulacion_recibida', null, null, ['fuente' => $postulacion->fuente], 'candidato');
                $invitacion?->forceFill(['usada_at' => now(), 'candidato_id' => $candidato->id])->save();

                return $postulacion;
            });
        } catch (UniqueConstraintViolationException) {
            $this->limpiarArchivo($archivoGuardado);
            throw ValidationException::withMessages(['proceso' => 'Ya postulaste a este proceso. Puedes revisar su estado en "Mis postulaciones".']);
        } catch (\Throwable $e) {
            $this->limpiarArchivo($archivoGuardado);
            throw $e;
        }

        // Extracción/ATS en cola con el contexto de empresa resuelto por el
        // servidor; si falla, la postulación ya quedó registrada.
        EvaluarPostulacionJob::dispatch($postulacion->empresa_id, $postulacion->id)->afterCommit();

        $this->correo->enviar(
            $acceso->email,
            'Recibimos tu postulación',
            "Recibimos tu postulación al puesto {$proceso->cargo}.\nCódigo de seguimiento: {$postulacion->codigo_seguimiento}\n\n"
            .'Para consultar su estado ingresa a '.$this->correo->urlPortal('/empleos/mis-postulaciones').' con tu correo verificado.',
        );

        return $postulacion;
    }

    /**
     * Postulaciones del correo verificado (puede abarcar varias empresas: son
     * los datos del propio candidato). Sin notas internas ni puntajes.
     */
    public function misPostulaciones(AccesoCandidato $acceso): Collection
    {
        return Postulacion::query()
            ->whereHas('candidato', fn ($q) => $q->where('email', $acceso->email)->whereNull('anonimizado_at'))
            ->with([
                'proceso:id,empresa_id,codigo,cargo,estado,empresa_confidencial,mensaje_publico,modalidad,ubicacion',
                'proceso.empresa:id,nombre_comercial',
                'asignacionesPrueba' => fn ($q) => $q->whereIn('estado', ['pendiente', 'en_curso'])->with('prueba:id,nombre,tiempo_limite_minutos'),
            ])
            ->latest('id')
            ->get();
    }

    public function postulacionPropia(AccesoCandidato $acceso, int $postulacionId): Postulacion
    {
        $postulacion = Postulacion::whereKey($postulacionId)
            ->whereHas('candidato', fn ($q) => $q->where('email', $acceso->email))
            ->first();

        if (! $postulacion) {
            throw new NotFoundHttpException;
        }

        return $postulacion;
    }

    private function invitacionVigente(ProcesoSeleccion $proceso, string $token): ?Invitacion
    {
        return Invitacion::where('proceso_id', $proceso->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expira_at', '>', now())
            ->first();
    }

    /**
     * Cada excluyente debe tener una declaración explícita sí/no del candidato.
     */
    private function validarDeclaraciones(?PerfilProceso $perfil, array $declaraciones): void
    {
        if (! $perfil) {
            throw ValidationException::withMessages(['proceso' => 'El proceso no tiene un perfil publicado.']);
        }

        foreach ($perfil->requisitos->where('tipo', 'excluyente') as $requisito) {
            if (! array_key_exists($requisito->id, $declaraciones) && ! array_key_exists((string) $requisito->id, $declaraciones)) {
                throw ValidationException::withMessages(["requisitos.{$requisito->id}" => "Indica si cumples: {$requisito->descripcion}"]);
            }
        }
    }

    private function reemplazarPerfil(Candidato $candidato, array $datos): void
    {
        $candidato->antecedentes()->delete();
        foreach ($datos['experiencias'] ?? [] as $experiencia) {
            $candidato->antecedentes()->create([...$this->soloAntecedente($experiencia), 'tipo' => 'experiencia']);
        }
        foreach ($datos['estudios'] ?? [] as $estudio) {
            $candidato->antecedentes()->create([...$this->soloAntecedente($estudio), 'tipo' => 'estudio']);
        }

        $candidato->habilidades()->delete();
        foreach (collect($datos['habilidades'] ?? [])->unique(fn ($h) => mb_strtolower(trim($h['nombre']))) as $habilidad) {
            $candidato->habilidades()->create(['nombre' => trim($habilidad['nombre']), 'nivel' => $habilidad['nivel'] ?? null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function soloAntecedente(array $datos): array
    {
        return collect($datos)->only(['titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->all();
    }

    /**
     * Copia inmutable de lo declarado al postular: actualizar el perfil
     * después no modifica esta postulación.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Candidato $candidato, array $datos): array
    {
        return [
            'capturado_at' => now()->toIso8601String(),
            'nombres' => $candidato->nombres,
            'apellido_paterno' => $candidato->apellido_paterno,
            'apellido_materno' => $candidato->apellido_materno,
            'tipo_documento' => $candidato->tipo_documento,
            'numero_documento' => $candidato->numero_documento,
            'email' => $candidato->email,
            'celular' => $candidato->celular,
            'ubicacion' => $candidato->ubicacion,
            'resumen' => $candidato->resumen,
            'pretension_salarial' => $datos['pretension_salarial'] ?? null,
            'experiencias' => $candidato->antecedentes->where('tipo', 'experiencia')->map->only(['titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->values()->all(),
            'estudios' => $candidato->antecedentes->where('tipo', 'estudio')->map->only(['titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->values()->all(),
            'habilidades' => $candidato->habilidades->map->only(['nombre', 'nivel'])->values()->all(),
            'requisitos' => collect($datos['requisitos'] ?? [])->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))->all(),
        ];
    }

    private function codigoSeguimiento(): string
    {
        do {
            $codigo = 'P'.strtoupper(Str::random(9));
        } while (Postulacion::withoutGlobalScopes()->where('codigo_seguimiento', $codigo)->exists());

        return $codigo;
    }

    private function limpiarArchivo($documento): void
    {
        if ($documento?->ruta) {
            Storage::disk($documento->disco)->delete($documento->ruta);
        }
    }
}
