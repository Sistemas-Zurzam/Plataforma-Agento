<?php

namespace App\Modules\Configuracion\Services;

use App\Modules\Configuracion\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleService
{
    /**
     * @param  array{nombre: string}  $datos
     */
    public function crear(array $datos): Role
    {
        return Role::create([
            'nombre' => $datos['nombre'],
            'clave' => $this->claveUnica($datos['nombre']),
        ]);
    }

    /**
     * @param  array{nombre: string}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(Role $rol, array $datos): Role
    {
        $this->prohibirAdministrador($rol, 'editar');

        $rol->update(['nombre' => $datos['nombre']]);

        return $rol;
    }

    /**
     * @throws ValidationException
     */
    public function eliminar(Role $rol): void
    {
        $this->prohibirAdministrador($rol, 'eliminar');

        if ($rol->empresaUsuarios()->exists()) {
            throw ValidationException::withMessages([
                'rol' => 'No puedes eliminar un rol que todavía tiene usuarios asignados.',
            ]);
        }

        $rol->delete();
    }

    /**
     * @throws ValidationException
     */
    private function prohibirAdministrador(Role $rol, string $accion): void
    {
        if ($rol->clave === Role::ADMINISTRADOR) {
            throw ValidationException::withMessages([
                'rol' => "No puedes {$accion} el rol administrador.",
            ]);
        }
    }

    private function claveUnica(string $nombre): string
    {
        $base = Str::slug($nombre, '_');
        $clave = $base;
        $sufijo = 1;

        while (Role::where('clave', $clave)->exists()) {
            $sufijo++;
            $clave = "{$base}_{$sufijo}";
        }

        return $clave;
    }
}
