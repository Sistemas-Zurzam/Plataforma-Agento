<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rol = $this->pivot?->role;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'empresa' => $this->empresaActiva,
            'area' => $this->area ? [
                'id' => $this->area->id,
                'nombre' => $this->area->nombre,
            ] : null,
            'role' => $rol ? [
                'id' => $rol->id,
                'clave' => $rol->clave,
                'nombre' => $rol->nombre,
            ] : null,
            'es_actual' => $this->id === $request->user('api')?->id,
        ];
    }
}
