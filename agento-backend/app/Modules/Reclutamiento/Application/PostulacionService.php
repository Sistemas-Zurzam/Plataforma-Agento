<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\FlujoProceso;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\PostulacionMovimiento;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostulacionService
{
    public const MENSAJE_PUBLICO_DESCARTE = 'Gracias por tu interés. En esta ocasión tu postulación no continuará en el proceso.';

    public const MENSAJE_PUBLICO_CANCELACION = 'El proceso de selección fue cancelado por la empresa.';

    public function __construct(
        private readonly RegistroActividad $actividad,
        private readonly PuntajeFinalService $puntajes,
        private readonly EtiquetadoAutomatico $etiquetas,
    ) {}

    public function listar(AlcanceSeleccion $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $this->consulta($alcance, $filtros)
            ->orderByDesc('etapa_desde_at')
            ->paginate($perPage);
    }

    /**
     * Tablero: postulaciones vigentes agrupadas por etapa (hasta $limite por
     * columna) y el total real de cada etapa calculado en backend.
     *
     * @return array{columnas: array<int, array{etapa: string, total: int, postulaciones: Collection}>}
     */
    public function tablero(AlcanceSeleccion $alcance, ProcesoSeleccion $proceso, array $filtros, int $limite = 50): array
    {
        $columnas = [];
        foreach (FlujoPostulacion::ETAPAS as $etapa) {
            $consulta = $this->consulta($alcance, [...$filtros, 'proceso_id' => $proceso->id, 'etapa' => $etapa, 'vigentes' => true]);
            $columnas[] = [
                'etapa' => $etapa,
                'total' => (clone $consulta)->count(),
                'postulaciones' => $consulta->orderBy('etapa_desde_at')->limit($limite)->get(),
            ];
        }

        return ['columnas' => $columnas];
    }

    public function mover(AlcanceSeleccion $alcance, Postulacion $postulacion, string $etapa, ?string $motivo): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($alcance, $etapa, $motivo) {
            $this->exigirProcesoGestionable($p);
            FlujoPostulacion::mover($p->etapa, $p->estado, $etapa, $motivo);
            $this->aplicar($p, $etapa, $p->estado, $motivo, $alcance->usuario->id);

            if ($etapa === 'seleccion') {
                $this->etiquetas->aplicar($p->empresa_id, $p->candidato_id, 'finalista');
            }
        });
    }

    public function seleccionar(AlcanceSeleccion $alcance, Postulacion $postulacion, ?string $motivo): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($alcance, $motivo) {
            $this->exigirProcesoGestionable($p);
            $estado = FlujoPostulacion::seleccionar($p->etapa, $p->estado);
            $this->aplicar($p, $p->etapa, $estado, $motivo, $alcance->usuario->id);
        });
    }

    /**
     * El motivo interno nunca se muestra al candidato; el mensaje público es
     * aparte (con un texto neutro por defecto).
     */
    public function descartar(AlcanceSeleccion $alcance, Postulacion $postulacion, string $causa, string $motivo, ?string $mensajePublico): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($alcance, $causa, $motivo, $mensajePublico) {
            $estado = FlujoPostulacion::descartar($p->estado, $causa, $motivo);
            $p->fill([
                'causa_descarte' => $causa,
                'motivo_interno' => $motivo,
                'mensaje_publico' => $mensajePublico ?: self::MENSAJE_PUBLICO_DESCARTE,
            ]);
            $this->aplicar($p, $p->etapa, $estado, $motivo, $alcance->usuario->id);
            $this->anularPruebasPendientes($p);
        });
    }

    public function reconsiderar(AlcanceSeleccion $alcance, Postulacion $postulacion, string $motivo): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($alcance, $motivo) {
            $this->exigirProcesoGestionable($p);
            $estado = FlujoPostulacion::reconsiderar($p->estado, $motivo);
            $p->fill(['causa_descarte' => null, 'motivo_interno' => null, 'mensaje_publico' => null]);
            $this->aplicar($p, $p->etapa, $estado, $motivo, $alcance->usuario->id);
        });
    }

    /**
     * Retiro registrado por RR. HH. (por ejemplo, el candidato avisó por
     * teléfono) o por el propio candidato desde el portal ($actorUserId null).
     */
    public function retirar(Postulacion $postulacion, ?string $motivo, ?int $actorUserId): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($motivo, $actorUserId) {
            $estado = FlujoPostulacion::retirar($p->estado);
            $p->fill(['mensaje_publico' => 'Retiraste tu postulación.']);
            $this->aplicar($p, $p->etapa, $estado, $motivo, $actorUserId, $actorUserId ? 'usuario' : 'candidato');
            $this->anularPruebasPendientes($p);
        });
    }

    /**
     * @param  'ats'|'psicometria'|'entrevistas'  $componente
     */
    public function marcarNoAplica(AlcanceSeleccion $alcance, Postulacion $postulacion, string $componente, bool $noAplica, string $motivo): Postulacion
    {
        return $this->transicion($postulacion, function (Postulacion $p) use ($alcance, $componente, $noAplica, $motivo) {
            $p->update(["no_aplica_{$componente}" => $noAplica]);
            $this->actividad->registrar($p->empresa_id, $p, $noAplica ? 'componente_no_aplica' : 'componente_aplica', $alcance->usuario->id, $motivo, [
                'componente' => $componente,
            ]);
            $this->puntajes->recalcular($p);
        });
    }

    /**
     * Acciones múltiples con resultado individual: un fallo no detiene a las
     * demás postulaciones ni revierte las ya procesadas.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{id: int, ok: bool, mensaje: string}>
     */
    public function accionMasiva(AlcanceSeleccion $alcance, array $ids, string $accion, array $datos): array
    {
        $postulaciones = Postulacion::where('empresa_id', $alcance->empresaId)->whereIn('id', $ids)->get()->keyBy('id');
        $resultados = [];

        foreach (array_unique($ids) as $id) {
            $postulacion = $postulaciones->get($id);
            if (! $postulacion) {
                $resultados[] = ['id' => (int) $id, 'ok' => false, 'mensaje' => 'La postulación no existe en la empresa activa.'];

                continue;
            }

            try {
                match ($accion) {
                    'mover' => $this->mover($alcance, $postulacion, $datos['etapa'], $datos['motivo'] ?? null),
                    'descartar' => $this->descartar($alcance, $postulacion, $datos['causa'], $datos['motivo'], $datos['mensaje_publico'] ?? null),
                };
                $resultados[] = ['id' => (int) $id, 'ok' => true, 'mensaje' => 'Acción aplicada.'];
            } catch (TransicionInvalidaException|ValidationException $e) {
                $resultados[] = ['id' => (int) $id, 'ok' => false, 'mensaje' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $resultados[] = ['id' => (int) $id, 'ok' => false, 'mensaje' => 'No se pudo aplicar la acción.'];
            }
        }

        return $resultados;
    }

    /**
     * Al cancelar un proceso: toda postulación vigente termina con un estado
     * propio (finalizada_por_cancelacion), distinto de un descarte.
     */
    public function finalizarPorCancelacion(ProcesoSeleccion $proceso, ?int $usuarioId): int
    {
        $vigentes = Postulacion::where('proceso_id', $proceso->id)
            ->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA])
            ->lockForUpdate()
            ->get();

        foreach ($vigentes as $postulacion) {
            $estado = FlujoPostulacion::finalizarPorCancelacion($postulacion->estado);
            $postulacion->fill(['causa_descarte' => 'proceso_cancelado', 'mensaje_publico' => self::MENSAJE_PUBLICO_CANCELACION]);
            $this->aplicar($postulacion, $postulacion->etapa, $estado, 'Proceso cancelado', $usuarioId);
            $this->anularPruebasPendientes($postulacion);
        }

        return $vigentes->count();
    }

    public function registrarMovimiento(Postulacion $postulacion, ?string $etapaAnterior, ?string $estadoAnterior, ?string $motivo, ?int $actorUserId, string $actorTipo = 'usuario'): void
    {
        PostulacionMovimiento::create([
            'empresa_id' => $postulacion->empresa_id,
            'postulacion_id' => $postulacion->id,
            'etapa_anterior' => $etapaAnterior,
            'etapa_nueva' => $postulacion->etapa,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $postulacion->estado,
            'motivo' => $motivo,
            'actor_user_id' => $actorUserId,
            'actor_tipo' => $actorUserId ? $actorTipo : ($actorTipo === 'usuario' ? 'sistema' : $actorTipo),
        ]);
    }

    private function consulta(AlcanceSeleccion $alcance, array $filtros)
    {
        return Postulacion::query()
            ->where('seleccion_postulaciones.empresa_id', $alcance->empresaId)
            ->when(! $alcance->accesoTotal(), fn ($q) => $q->whereHas('proceso', fn ($p) => $p->whereIn('area_id', $alcance->areaIds)))
            ->when($filtros['proceso_id'] ?? null, fn ($q, $id) => $q->where('proceso_id', $id))
            ->when($filtros['etapa'] ?? null, fn ($q, $etapa) => $q->where('etapa', $etapa))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['fuente'] ?? null, fn ($q, $fuente) => $q->where('fuente', $fuente))
            ->when($filtros['vigentes'] ?? false, fn ($q) => $q->whereIn('estado', [FlujoPostulacion::ACTIVA, FlujoPostulacion::SELECCIONADA]))
            ->when($filtros['busqueda'] ?? null, fn ($q, $texto) => $q->whereHas('candidato', fn ($c) => $c->where(fn ($w) => $w
                ->where('nombres', 'like', "%{$texto}%")
                ->orWhere('apellido_paterno', 'like', "%{$texto}%")
                ->orWhere('email', 'like', "%{$texto}%")
                ->orWhere('numero_documento', 'like', "%{$texto}%"))))
            ->with([
                'candidato:id,nombres,apellido_paterno,apellido_materno,email,celular',
                'proceso:id,codigo,cargo,area_id,estado',
                'evaluacionAtsVigente', 'puntajeFinalVigente',
            ])
            ->withCount([
                'entrevistas as entrevistas_pendientes' => fn ($q) => $q->where('estado', 'programada'),
                'asignacionesPrueba as pruebas_pendientes' => fn ($q) => $q->whereIn('estado', [PruebaAsignacion::PENDIENTE, PruebaAsignacion::EN_CURSO]),
            ]);
    }

    /**
     * Bloquea la fila, revalida el estado y ejecuta la transición en una
     * transacción (evita dobles clics y carreras entre usuarios).
     */
    private function transicion(Postulacion $postulacion, callable $accion): Postulacion
    {
        DB::transaction(function () use ($postulacion, $accion) {
            $bloqueada = Postulacion::whereKey($postulacion->id)->lockForUpdate()->firstOrFail();
            $accion($bloqueada);
        });

        return $postulacion->refresh();
    }

    private function aplicar(Postulacion $postulacion, string $etapa, string $estado, ?string $motivo, ?int $actorUserId, string $actorTipo = 'usuario'): void
    {
        $etapaAnterior = $postulacion->etapa;
        $estadoAnterior = $postulacion->estado;

        $postulacion->fill(['etapa' => $etapa, 'estado' => $estado]);
        if ($etapa !== $etapaAnterior) {
            $postulacion->etapa_desde_at = now();
        }
        $postulacion->save();

        $this->registrarMovimiento($postulacion, $etapaAnterior, $estadoAnterior, $motivo, $actorUserId, $actorTipo);
    }

    private function exigirProcesoGestionable(Postulacion $postulacion): void
    {
        $estadoProceso = ProcesoSeleccion::whereKey($postulacion->proceso_id)->value('estado');
        if (! FlujoProceso::admiteGestionDeCandidatos((string) $estadoProceso)) {
            throw new TransicionInvalidaException("El proceso está {$estadoProceso}: no admite mover candidatos.");
        }
    }

    private function anularPruebasPendientes(Postulacion $postulacion): void
    {
        PruebaAsignacion::where('postulacion_id', $postulacion->id)
            ->where('estado', PruebaAsignacion::PENDIENTE)
            ->update(['estado' => PruebaAsignacion::ANULADA]);
    }
}
