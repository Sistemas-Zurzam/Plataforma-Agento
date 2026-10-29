<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TareaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'detalle' => $this->detalle,
            'estado' => $this->estado,
            'colaborador' => $this->colaborador ? [
                'id' => $this->colaborador->id, 'legajo' => $this->colaborador->legajo,
                'nombre' => "{$this->colaborador->nombres} {$this->colaborador->apellidos}",
            ] : null,
            'completada_at' => $this->completada_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
