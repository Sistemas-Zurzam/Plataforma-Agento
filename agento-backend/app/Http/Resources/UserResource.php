<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->currentRole()?->clave,
            'empresa' => [
                'id' => $this->empresa->id,
                'nombre' => $this->empresa->nombre,
            ],
            'area' => $this->area ? [
                'id' => $this->area->id,
                'nombre' => $this->area->nombre,
            ] : null,
        ];
    }
}
