<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\CalculadoraAts;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Models\Candidato;
use App\Modules\Reclutamiento\Models\Etiqueta;
use App\Modules\Reclutamiento\Models\Invitacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Banco de talentos acotado a la empresa activa: candidatos, etiquetas,
 * historial, coincidencias explicables con procesos e invitaciones. Nunca
 * cruza datos (CV, entrevistas, resultados) entre empresas.
 */
class BancoTalentosService
{
    public function __construct(
        private readonly CorreoReclutamiento $correo,
        private readonly RegistroActividad $actividad,
    ) {}

    public function buscar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return Candidato::query()
            ->where('empresa_id', $alcance->empresaId)
            ->whereNull('anonimizado_at')
            ->when($filtros['busqueda'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('nombres', 'like', "%{$t}%")->orWhere('apellido_paterno', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")))
            ->when($filtros['cargo'] ?? null, fn ($q, $cargo) => $q->whereHas('postulaciones.proceso', fn ($p) => $p->where('cargo', 'like', "%{$cargo}%")))
            ->when($filtros['area_id'] ?? null, fn ($q, $area) => $q->whereHas('postulaciones.proceso', fn ($p) => $p->where('area_id', $area)))
            ->when($filtros['competencia'] ?? null, fn ($q, $c) => $q->whereHas('habilidades', fn ($h) => $h->where('nombre', 'like', "%{$c}%")))
            ->when($filtros['etiqueta'] ?? null, fn ($q, $e) => $q->whereHas('etiquetas', fn ($t) => $t->where('nombre', $e)))
            ->with(['habilidades', 'etiquetas'])
            ->withCount('postulaciones')
            ->orderByDesc('ultima_postulacion_at')
            ->paginate($perPage);
    }

    public function detalle(Candidato $candidato): Candidato
    {
        return $candidato->load([
            'habilidades', 'etiquetas', 'antecedentes',
            'postulaciones' => fn ($q) => $q->latest('id')->with('proceso:id,codigo,cargo,area_id,estado', 'proceso.area:id,nombre', 'evaluacionAtsVigente', 'puntajeFinalVigente'),
            'consentimientos' => fn ($q) => $q->latest('id'),
        ]);
    }

    public function etiquetar(AlcanceSeleccion $alcance, Candidato $candidato, string $nombre): Candidato
    {
        $nombre = Str::of($nombre)->trim()->lower()->limit(60, '')->toString();
        if (in_array($nombre, EtiquetadoAutomatico::NOMBRES, true)) {
            throw ValidationException::withMessages(['nombre' => 'Esa etiqueta es automática y no se asigna manualmente.']);
        }

        $etiqueta = Etiqueta::firstOrCreate(['empresa_id' => $alcance->empresaId, 'nombre' => $nombre], ['tipo' => Etiqueta::MANUAL]);
        $candidato->etiquetas()->syncWithoutDetaching([$etiqueta->id => ['origen' => Etiqueta::MANUAL]]);
        $this->actividad->registrar($alcance->empresaId, $candidato, 'etiqueta_agregada', $alcance->usuario->id, null, ['etiqueta' => $nombre]);

        return $candidato->load('etiquetas');
    }

    public function quitarEtiqueta(AlcanceSeleccion $alcance, Candidato $candidato, Etiqueta $etiqueta): Candidato
    {
        if ($etiqueta->empresa_id !== $alcance->empresaId) {
            throw ValidationException::withMessages(['etiqueta' => 'Etiqueta no válida.']);
        }
        $candidato->etiquetas()->detach($etiqueta->id);
        $this->actividad->registrar($alcance->empresaId, $candidato, 'etiqueta_quitada', $alcance->usuario->id, null, ['etiqueta' => $etiqueta->nombre]);

        return $candidato->load('etiquetas');
    }

    /**
     * Coincidencias explicables con un proceso: por cada competencia del
     * perfil, si el candidato la declaró y con qué nivel; más antecedentes
     * del banco (finalista, postulaciones previas al mismo cargo).
     *   puntaje = Σ(peso × min(nivel_declarado / nivel_requerido, 1)) — una
     *   competencia no declarada aporta 0 aquí porque es solo un orden de
     *   búsqueda (no un ATS ni una decisión).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function coincidencias(ProcesoSeleccion $proceso, int $limite = 20): Collection
    {
        $perfil = $proceso->perfilVigente()->with('competencias')->first();
        $competencias = $perfil?->competencias ?? collect();
        $nombresNormalizados = $competencias->mapWithKeys(fn ($c) => [CalculadoraAts::normalizar($c->nombre) => $c]);

        return Candidato::query()
            ->where('empresa_id', $proceso->empresa_id)
            ->whereNull('anonimizado_at')
            ->whereDoesntHave('postulaciones', fn ($q) => $q->where('proceso_id', $proceso->id))
            ->with(['habilidades', 'etiquetas', 'postulaciones.proceso:id,cargo'])
            ->get()
            ->map(function (Candidato $candidato) use ($nombresNormalizados, $proceso) {
                $razones = [];
                $puntaje = 0.0;

                foreach ($candidato->habilidades as $habilidad) {
                    $competencia = $nombresNormalizados->get(CalculadoraAts::normalizar($habilidad->nombre));
                    if (! $competencia) {
                        continue;
                    }
                    $cobertura = $habilidad->nivel ? min($habilidad->nivel / max(1, $competencia->nivel), 1) : 0.5;
                    $puntaje += $competencia->peso * $cobertura;
                    $razones[] = $habilidad->nivel
                        ? "Declara {$habilidad->nombre} nivel {$habilidad->nivel} (requerido {$competencia->nivel})"
                        : "Declara {$habilidad->nombre} sin nivel (cuenta 50%)";
                }

                $cargoSimilar = $candidato->postulaciones->first(fn ($p) => $p->proceso && Str::contains(Str::lower($p->proceso->cargo), Str::lower($proceso->cargo)));
                if ($cargoSimilar) {
                    $razones[] = "Postuló antes a {$cargoSimilar->proceso->cargo}";
                }
                if ($candidato->etiquetas->contains('nombre', 'finalista')) {
                    $razones[] = 'Fue finalista en un proceso anterior';
                }

                return [
                    'candidato' => $candidato,
                    'puntaje' => round($puntaje, 2),
                    'razones' => $razones,
                ];
            })
            ->filter(fn ($c) => $c['razones'] !== [])
            ->sortByDesc('puntaje')
            ->take($limite)
            ->values();
    }

    /**
     * Invitación por correo con enlace a la publicación (con token cuando el
     * proceso no es público). Si el correo no se pudo enviar se informa y se
     * devuelve el enlace para compartirlo por otro medio.
     *
     * @return array{invitacion: Invitacion, enlace: string, correo_enviado: bool}
     */
    public function invitar(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, string $email, ?Candidato $candidato, string $tipo): array
    {
        if (! in_array($proceso->estado, [ProcesoSeleccion::ABIERTO], true)) {
            throw new TransicionInvalidaException('Solo se invita a procesos abiertos.');
        }
        if ($candidato && $candidato->empresa_id !== $proceso->empresa_id) {
            throw ValidationException::withMessages(['candidato_id' => 'El candidato no pertenece a la empresa.']);
        }
        if ($tipo === Invitacion::INTERNA && ! $proceso->publicacion_interna) {
            throw ValidationException::withMessages(['tipo' => 'Registra primero la publicación interna del proceso.']);
        }

        $token = Str::random(48);
        $invitacion = Invitacion::create([
            'empresa_id' => $proceso->empresa_id,
            'proceso_id' => $proceso->id,
            'candidato_id' => $candidato?->id,
            'email' => AccesoCandidatoService::normalizarEmail($email),
            'token_hash' => hash('sha256', $token),
            'tipo' => $tipo,
            'expira_at' => now()->addDays((int) config('reclutamiento.invitaciones.dias_vigencia', 15)),
            'creado_por' => $alcance->usuario->id,
        ]);

        $enlace = $this->correo->urlPortal("/empleos/{$proceso->codigo}?invitacion={$token}");
        $enviado = $this->correo->enviar(
            $invitacion->email,
            "Invitación a postular: {$proceso->cargo}",
            "Te invitamos a postular al puesto {$proceso->cargo}.\n\nIngresa aquí: {$enlace}\n\nEl enlace es personal y vence el {$invitacion->expira_at->format('d/m/Y')}.",
        );

        $this->actividad->registrar($alcance->empresaId, $proceso, 'invitacion_enviada', $alcance->usuario->id, null, [
            'tipo' => $tipo, 'candidato_id' => $candidato?->id, 'correo_enviado' => $enviado,
        ]);

        return ['invitacion' => $invitacion, 'enlace' => $enlace, 'correo_enviado' => $enviado];
    }
}
