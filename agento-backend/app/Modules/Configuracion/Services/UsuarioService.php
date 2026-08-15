<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function listar(Empresa $empresaActiva, int $perPage = 10): LengthAwarePaginator
    {
        return $empresaActiva->users()->with('area')->orderBy('name')->paginate($perPage);
    }

    /**
     * Crea un usuario nuevo y lo adjunta a la empresa indicada (ya validada
     * como una de las empresas del actor) con el rol y, opcionalmente, el
     * área señalados. La contraseña la define el propio administrador.
     *
     * @param  array{name: string, username: string, email: string, password: string, area_id: ?int}  $datos
     */
    public function crear(Empresa $empresaDestino, array $datos, Role $rol): User
    {
        return DB::transaction(function () use ($empresaDestino, $datos, $rol) {
            $usuario = User::create([
                ...$datos,
                'empresa_id' => $empresaDestino->id,
                'password' => Hash::make($datos['password']),
            ]);

            $empresaDestino->users()->attach($usuario->id, ['role_id' => $rol->id]);

            $usuario->setRelation(
                'pivot',
                $empresaDestino->users()->find($usuario->id)->pivot,
            );

            return $usuario;
        });
    }

    /**
     * Actualiza los datos básicos del usuario (nombre, usuario, email, área)
     * y, opcionalmente, su contraseña. No toca su rol ni su empresa: eso se
     * maneja por separado en cambiarRol() y en el flujo de creación,
     * respectivamente.
     *
     * @param  array{name: string, username: string, email: string, area_id: ?int, password: ?string}  $datos
     *
     * @throws AuthorizationException
     */
    public function actualizar(Empresa $empresaActiva, User $objetivo, array $datos): void
    {
        $this->verificarPertenencia($empresaActiva, $objetivo);

        if (! empty($datos['password'])) {
            $datos['password'] = Hash::make($datos['password']);
        } else {
            unset($datos['password']);
        }

        $objetivo->update($datos);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cambiarRol(Empresa $empresaActiva, User $objetivo, Role $nuevoRol, User $actor): void
    {
        $this->verificarPertenencia($empresaActiva, $objetivo);

        if ($objetivo->id === $actor->id) {
            throw ValidationException::withMessages([
                'rol' => 'No puedes cambiar tu propio rol.',
            ]);
        }

        if ($this->esRolAdministrador($nuevoRol->id) === false
            && $this->esUltimoAdministrador($empresaActiva, $objetivo)) {
            throw ValidationException::withMessages([
                'rol' => 'No puedes quitar el rol de administrador al último administrador de la empresa.',
            ]);
        }

        $empresaActiva->users()->updateExistingPivot($objetivo->id, ['role_id' => $nuevoRol->id]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function eliminar(Empresa $empresaActiva, User $objetivo, User $actor): void
    {
        $this->verificarPertenencia($empresaActiva, $objetivo);

        if ($objetivo->id === $actor->id) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes eliminarte a ti mismo.',
            ]);
        }

        if ($this->esUltimoAdministrador($empresaActiva, $objetivo)) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes eliminar al último administrador de la empresa.',
            ]);
        }

        $otrasEmpresas = $objetivo->empresas()->where('empresas.id', '!=', $empresaActiva->id);

        if ($otrasEmpresas->count() === 0) {
            throw ValidationException::withMessages([
                'usuario' => 'Este usuario no pertenece a ninguna otra empresa; no se puede eliminar de esta.',
            ]);
        }

        DB::transaction(function () use ($empresaActiva, $objetivo, $otrasEmpresas) {
            if ($objetivo->empresa_id === $empresaActiva->id) {
                $objetivo->update(['empresa_id' => $otrasEmpresas->first()->id]);
            }

            $empresaActiva->users()->detach($objetivo->id);
        });
    }

    private function verificarPertenencia(Empresa $empresaActiva, User $objetivo): void
    {
        $pertenece = $empresaActiva->users()->where('users.id', $objetivo->id)->exists();

        if (! $pertenece) {
            throw new AuthorizationException('Este usuario no pertenece a la empresa activa.');
        }
    }

    private function esRolAdministrador(int $roleId): bool
    {
        return $roleId === Role::administrador()->id;
    }

    private function esUltimoAdministrador(Empresa $empresaActiva, User $objetivo): bool
    {
        $rolActual = $empresaActiva->users()->where('users.id', $objetivo->id)->first()?->pivot->role_id;

        if ($rolActual !== Role::administrador()->id) {
            return false;
        }

        $totalAdministradores = $empresaActiva->users()
            ->wherePivot('role_id', Role::administrador()->id)
            ->count();

        return $totalAdministradores <= 1;
    }
}
