<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clave' => $this->clave,
            'nombre' => $this->nombre,
            'usuarios_count' => $this->usuarios_count ?? 0,
            'permission_ids' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('id'),
            ),
        ];
    }
}
