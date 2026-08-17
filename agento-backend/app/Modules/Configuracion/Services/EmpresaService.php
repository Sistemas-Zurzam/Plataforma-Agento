<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class EmpresaService
{
    /**
     * Create a new empresa and attach the creator as its administrator.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearParaUsuario(array $datos, User $usuario): Empresa
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $empresa = Empresa::create([...$datos, 'activa' => true]);

            $empresa->users()->attach($usuario->id, ['role_id' => Role::administrador()->id]);

            return $empresa->setRelation(
                'pivot',
                $empresa->users()->find($usuario->id)->pivot,
            );
        });
    }

    /**
     * Switch the user's currently active empresa.
     *
     * @throws AuthorizationException if the user has no access to the empresa.
     */
    public function activarParaUsuario(Empresa $empresa, User $usuario): void
    {
        if (! $this->tieneAcceso($empresa, $usuario)) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }

        if (! $empresa->activa) {
            throw new AuthorizationException('No puedes seleccionar una empresa inactiva.');
        }

        $usuario->update(['empresa_id' => $empresa->id]);
    }

    /**
     * Update an empresa's own data.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws AuthorizationException if the user is not an administrador of the empresa.
     */
    public function actualizar(Empresa $empresa, array $datos, User $usuario): Empresa
    {
        if (! $this->puedeEditar($empresa, $usuario)) {
            throw new AuthorizationException('No puedes editar esta empresa.');
        }

        $empresa->update($datos);

        return $empresa->setRelation(
            'pivot',
            $empresa->users()->find($usuario->id)->pivot,
        );
    }

    public function actualizarEstado(Empresa $empresa, bool $activa, User $usuario): Empresa
    {
        if (! $this->puedeEditar($empresa, $usuario)) {
            throw new AuthorizationException('No puedes cambiar el estado de esta empresa.');
        }

        $empresa->update(['activa' => $activa]);

        return $empresa->setRelation(
            'pivot',
            $empresa->users()->find($usuario->id)->pivot,
        );
    }

    /**
     * Autoriza una acción usando el rol que el usuario tiene en la empresa
     * objetivo, no el rol de la empresa que esté activa en ese momento.
     */
    public function autorizarAccion(Empresa $empresa, User $usuario, string $permiso): void
    {
        $vinculo = $usuario->empresas()
            ->where('empresas.id', $empresa->id)
            ->first();

        if (! $vinculo) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }

        $rol = Role::with('permissions')->find($vinculo->pivot->role_id);
        $autorizado = $rol?->clave === Role::ADMINISTRADOR
            || $rol?->permissions->contains('clave', $permiso);

        if (! $autorizado) {
            throw new AuthorizationException('No tienes permiso para realizar esta acción en esta empresa.');
        }
    }

    public function esAdministradorEn(Empresa $empresa, User $usuario): bool
    {
        return $usuario->empresas()
            ->where('empresas.id', $empresa->id)
            ->wherePivot('role_id', Role::administrador()->id)
            ->exists();
    }

    private function tieneAcceso(Empresa $empresa, User $usuario): bool
    {
        return $usuario->empresas()->where('empresas.id', $empresa->id)->exists();
    }

    private function puedeEditar(Empresa $empresa, User $usuario): bool
    {
        return $usuario->empresas()
            ->where('empresas.id', $empresa->id)
            ->wherePivot('role_id', Role::administrador()->id)
            ->exists();
    }
}
