<?php

namespace App\Http\Resources;

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
            'role' => $rol ? [
                'id' => $rol->id,
                'clave' => $rol->clave,
                'nombre' => $rol->nombre,
            ] : null,
            'es_actual' => $this->id === $request->user('api')?->id,
        ];
    }
}
