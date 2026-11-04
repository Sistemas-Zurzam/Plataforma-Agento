<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Candidato del banco de talentos (empresa activa).
 */
class CandidatoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombreCompleto(),
            'email' => $this->email,
            'celular' => $this->celular,
            'tipo_documento' => $this->tipo_documento,
            'numero_documento' => $this->numero_documento,
            'ubicacion' => $this->ubicacion,
            'resumen' => $this->resumen,
            'ultima_postulacion_at' => $this->ultima_postulacion_at?->toIso8601String(),
            'postulaciones_count' => $this->whenCounted('postulaciones'),
            'habilidades' => $this->whenLoaded('habilidades', fn () => $this->habilidades->map->only(['nombre', 'nivel'])->values()),
            'etiquetas' => $this->whenLoaded('etiquetas', fn () => $this->etiquetas->map(fn ($e) => [
                'id' => $e->id, 'nombre' => $e->nombre, 'tipo' => $e->tipo,
            ])->values()),
            'antecedentes' => $this->whenLoaded('antecedentes', fn () => $this->antecedentes->map->only(['tipo', 'titulo', 'institucion', 'fecha_inicio', 'fecha_fin', 'descripcion'])->values()),
            'consentimiento' => $this->whenLoaded('consentimientos', fn () => $this->consentimientos->first() ? [
                'version' => $this->consentimientos->first()->version,
                'aceptado_at' => $this->consentimientos->first()->aceptado_at?->toIso8601String(),
            ] : null),
            'postulaciones' => $this->whenLoaded('postulaciones', fn () => PostulacionResource::collection($this->postulaciones)),
        ];
    }
}
