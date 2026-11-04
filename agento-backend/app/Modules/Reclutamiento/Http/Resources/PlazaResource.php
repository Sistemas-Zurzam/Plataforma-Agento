<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlazaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $candidato = $this->relationLoaded('postulacion') ? $this->postulacion?->candidato : null;

        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'estado' => $this->estado,
            'postulacion_id' => $this->postulacion_id,
            'candidato' => $candidato ? $candidato->nombreCompleto() : null,
            'colaborador' => $this->relationLoaded('colaborador') && $this->colaborador ? [
                'id' => $this->colaborador->id,
                'legajo' => $this->colaborador->legajo,
                'nombre' => "{$this->colaborador->nombres} {$this->colaborador->apellidos}",
            ] : null,
            'cubierta_at' => $this->cubierta_at?->toIso8601String(),
            'cancelada_at' => $this->cancelada_at?->toIso8601String(),
            'motivo_cancelacion' => $this->motivo_cancelacion,
        ];
    }
}
