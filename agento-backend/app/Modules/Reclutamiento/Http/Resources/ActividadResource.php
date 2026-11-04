<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActividadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entidad' => $this->entidad_tipo,
            'accion' => $this->accion,
            'actor' => $this->actor?->name ?? ($this->actor_tipo === 'candidato' ? 'Candidato' : 'Sistema'),
            'motivo' => $this->motivo,
            'cambios' => $this->cambios,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
