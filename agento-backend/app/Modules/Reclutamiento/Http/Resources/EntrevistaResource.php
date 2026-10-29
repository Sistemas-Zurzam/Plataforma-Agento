<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntrevistaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'postulacion_id' => $this->postulacion_id,
            'tipo' => $this->tipo,
            'programada_at' => $this->programada_at?->toIso8601String(),
            'lugar' => $this->lugar,
            'estado' => $this->estado,
            'evaluador' => $this->evaluador ? ['id' => $this->evaluador->id, 'nombre' => $this->evaluador->name] : null,
            'evaluaciones' => $this->whenLoaded('evaluaciones', fn () => $this->evaluaciones->map(fn ($e) => [
                'id' => $e->id,
                'evaluador' => $e->evaluador?->name,
                'fortalezas' => $e->fortalezas,
                'riesgos' => $e->riesgos,
                'recomendacion' => $e->recomendacion,
                'comentarios' => $e->comentarios,
                'promedio' => $e->promedio,
                'puntaje' => $e->puntaje,
                'calificaciones' => $e->calificaciones->map->only(['competencia', 'calificacion'])->values(),
                'created_at' => $e->created_at?->toIso8601String(),
            ])),
            'candidato' => $this->whenLoaded('postulacion', fn () => $this->postulacion->relationLoaded('candidato') ? [
                'nombre' => $this->postulacion->candidato->nombreCompleto(),
                'proceso' => $this->postulacion->relationLoaded('proceso') ? $this->postulacion->proceso->cargo : null,
            ] : null),
        ];
    }
}
