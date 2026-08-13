<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    /**
     * @return Collection<int, User>
     */
    public function listar(Empresa $empresaActiva): Collection
    {
        return $empresaActiva->users()->orderBy('name')->get();
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
