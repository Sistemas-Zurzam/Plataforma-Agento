<?php

namespace App\Http\Resources;

use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rol = $this->currentRole();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $rol?->clave,
            'permisos' => $this->permisos($rol),
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

    /**
     * El rol administrador siempre tiene acceso total (ver EnsurePermission),
     * así que se le devuelven todas las claves sin depender de que la matriz
     * esté sincronizada — evita que el frontend oculte botones que el
     * backend igual permitiría.
     *
     * @return Collection<int, string>
     */
    private function permisos(?Role $rol): Collection
    {
        if (! $rol) {
            return collect();
        }

        return $rol->clave === Role::ADMINISTRADOR
            ? Permission::pluck('clave')
            : $rol->permissions->pluck('clave');
    }
}
