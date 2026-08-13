<?php

namespace App\Services;

use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
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
