<?php

namespace App\Modules\Configuracion\Services;

use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    /**
     * @return array{roles: Collection<int, Role>, grupos: Collection<string, Collection<int, Permission>>}
     */
    public function listar(): array
    {
        return [
            'roles' => Role::with('permissions')->orderBy('id')->get(),
            'grupos' => Permission::orderBy('id')->get()->groupBy('grupo'),
        ];
    }

    /**
     * Reemplaza las asignaciones de permisos por rol en una sola
     * transacción. El rol administrador se ignora: siempre tiene acceso
     * total y no es editable desde aquí (ver EnsurePermission).
     *
     * @param  array<int, array<int>>  $asignaciones  role_id => [permission_id, ...]
     */
    public function sincronizar(array $asignaciones): void
    {
        DB::transaction(function () use ($asignaciones) {
            $administradorId = Role::administrador()->id;

            foreach ($asignaciones as $roleId => $permissionIds) {
                if ((int) $roleId === $administradorId) {
                    continue;
                }

                Role::findOrFail($roleId)->permissions()->sync($permissionIds);
            }
        });
    }
}
