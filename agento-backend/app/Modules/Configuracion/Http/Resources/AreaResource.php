<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AreaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'responsable_user_id' => $this->responsable_user_id,
            'responsable_nombre' => $this->whenLoaded('responsable', fn () => $this->responsable?->name),
        ];
    }
}
