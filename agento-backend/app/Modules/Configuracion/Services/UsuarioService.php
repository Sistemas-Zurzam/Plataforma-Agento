<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UsuarioService
{
    public function __construct(private readonly EmpresaService $empresas) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function listar(Empresa $empresaActiva, int $perPage = 10): LengthAwarePaginator
    {
        return $empresaActiva->users()->with('area')->orderBy('name')->paginate($perPage);
    }

    /**
     * Crea un usuario nuevo y lo adjunta a TODAS las empresas indicadas (ya
     * validadas como accesibles para el actor) con el mismo rol, y
     * opcionalmente un área. La primera empresa de la lista queda como su
     * "empresa activa" inicial. La contraseña la define el propio
     * administrador.
     *
     * Si el rol es Administrador, alcanza con una sola empresa en la lista:
     * User::esAdministradorGlobal() ya le da acceso a todas sin necesitar
     * una fila por cada una — es el frontend el que decide cuántas mandar
     * según el rol elegido, este método no lo asume.
     *
     * @param  Collection<int, Empresa>  $empresasDestino
     * @param  array{name: string, username: string, email: string, password: string, area_id: ?int}  $datos
     */
    public function crear(Collection $empresasDestino, array $datos, Role $rol, User $actor): User
    {
        foreach ($empresasDestino as $empresa) {
            $this->empresas->autorizarAccion($empresa, $actor, 'usuarios.crear');
            $this->autorizarAsignacionRol($empresa, $rol, $actor);
        }

        $empresaPrincipal = $empresasDestino->first();

        return DB::transaction(function () use ($empresasDestino, $empresaPrincipal, $datos, $rol) {
            $usuario = User::create([
                ...$datos,
                'empresa_id' => $empresaPrincipal->id,
                'password' => Hash::make($datos['password']),
            ]);

            foreach ($empresasDestino as $empresa) {
                $empresa->users()->attach($usuario->id, ['role_id' => $rol->id]);
            }

            $usuario->setRelation(
                'pivot',
                $empresaPrincipal->users()->find($usuario->id)->pivot,
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
            $datos['token_version'] = $objetivo->token_version + 1;
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
        $this->empresas->autorizarAccion($empresaActiva, $actor, 'usuarios.cambiar_rol');
        $this->verificarPertenencia($empresaActiva, $objetivo);
        $this->autorizarAsignacionRol($empresaActiva, $nuevoRol, $actor);

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

    public function cambiarEstado(Empresa $empresaActiva, User $objetivo, bool $activo, User $actor): User
    {
        $this->empresas->autorizarAccion($empresaActiva, $actor, 'usuarios.inactivar');
        $this->verificarPertenencia($empresaActiva, $objetivo);

        if ($objetivo->id === $actor->id && ! $activo) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes inactivar tu propia cuenta.',
            ]);
        }

        if (! $activo && $this->esUltimoAdministrador($empresaActiva, $objetivo)) {
            throw ValidationException::withMessages([
                'usuario' => 'No puedes inactivar al último administrador de la empresa.',
            ]);
        }

        if (! $activo && $objetivo->empresas()->count() > 1) {
            throw ValidationException::withMessages([
                'usuario' => 'La cuenta pertenece a varias empresas. Retírala de esta empresa en lugar de inactivarla globalmente.',
            ]);
        }

        if ($objetivo->activo !== $activo) {
            $objetivo->update([
                'activo' => $activo,
                'token_version' => $objetivo->token_version + 1,
            ]);
        }

        return $objetivo;
    }

    private function autorizarAsignacionRol(Empresa $empresa, Role $rol, User $actor): void
    {
        if ($rol->clave === Role::ADMINISTRADOR
            && ! $this->empresas->esAdministradorEn($empresa, $actor)) {
            throw new AuthorizationException('Solo un administrador de la empresa puede asignar ese rol.');
        }
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
