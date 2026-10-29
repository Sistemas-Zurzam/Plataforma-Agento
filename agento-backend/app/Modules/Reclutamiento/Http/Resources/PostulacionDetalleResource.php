<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;

/**
 * Ficha completa de una postulación (solo para quien gestiona candidatos o
 * evaluaciones; la ruta ya lo exige).
 */
class PostulacionDetalleResource extends PostulacionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'snapshot' => $this->perfil_snapshot,
            'perfil_version' => $this->perfil_version,
            'cv' => $this->relationLoaded('cv') && $this->cv ? [
                'id' => $this->cv->id,
                'nombre' => $this->cv->nombre_original,
                'tamano' => $this->cv->tamano,
                'disponible' => $this->cv->eliminado_at === null,
            ] : null,
            'evaluaciones_ats' => $this->whenLoaded('evaluacionesAts', fn () => $this->evaluacionesAts->map(fn ($e) => [
                'id' => $e->id,
                'puntaje' => $e->puntaje,
                'resultado' => $e->resultado,
                'origen' => $e->origen,
                'proveedor' => $e->proveedor,
                'formula' => $e->formula,
                'perfil_version' => $e->perfil_version,
                'desglose' => $e->desglose,
                'evaluado_por' => $e->evaluador?->name,
                'created_at' => $e->created_at?->toIso8601String(),
            ])),
            'entrevistas' => $this->whenLoaded('entrevistas', fn () => EntrevistaResource::collection($this->entrevistas)),
            'pruebas' => $this->whenLoaded('asignacionesPrueba', fn () => $this->asignacionesPrueba->map(fn ($a) => [
                'id' => $a->id,
                'prueba' => ['id' => $a->prueba->id, 'nombre' => $a->prueba->nombre, 'version' => $a->prueba->version, 'validada' => $a->prueba->validada],
                'estado' => $a->estado,
                'disponible_hasta' => $a->disponible_hasta?->toIso8601String(),
                'intento' => $a->intento ? [
                    'iniciado_at' => $a->intento->iniciado_at?->toIso8601String(),
                    'finalizado_at' => $a->intento->finalizado_at?->toIso8601String(),
                    'cierre' => $a->intento->cierre,
                    'puntaje' => $a->intento->puntaje,
                    'dimensiones' => $a->intento->resultados->map(fn ($r) => ['dimension' => $r->dimension?->nombre, 'puntaje' => $r->puntaje]),
                ] : null,
            ])),
            'movimientos' => $this->whenLoaded('movimientos', fn () => $this->movimientos->map(fn ($m) => [
                'etapa_anterior' => $m->etapa_anterior,
                'etapa_nueva' => $m->etapa_nueva,
                'estado_anterior' => $m->estado_anterior,
                'estado_nuevo' => $m->estado_nuevo,
                'motivo' => $m->motivo,
                'actor' => $m->actor?->name ?? ($m->actor_tipo === 'candidato' ? 'Candidato' : 'Sistema'),
                'created_at' => $m->created_at?->toIso8601String(),
            ])),
            'plaza' => $this->whenLoaded('plaza', fn () => $this->plaza ? ['numero' => $this->plaza->numero] : null),
        ];
    }
}
