<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SedeService
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Empresa $empresa, array $datos, User $usuario): Sede
    {
        $this->autorizar($empresa, $usuario);

        return DB::transaction(function () use ($empresa, $datos) {
            $siguiente = $empresa->sedes()->count() + 1;

            return $empresa->sedes()->create([
                ...$datos,
                'codigo' => sprintf('SD%03d', $siguiente),
                'activa' => true,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, Sede $sede, array $datos, User $usuario): Sede
    {
        $this->autorizar($empresa, $usuario);
        $this->verificarPertenencia($empresa, $sede);

        $sede->update($datos);

        return $sede;
    }

    /**
     * @throws AuthorizationException if the user has no access to the empresa.
     */
    private function autorizar(Empresa $empresa, User $usuario): void
    {
        $tieneAcceso = $usuario->empresas()->where('empresas.id', $empresa->id)->exists();

        if (! $tieneAcceso) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }
    }

    private function verificarPertenencia(Empresa $empresa, Sede $sede): void
    {
        if ($sede->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Esta sede no pertenece a la empresa indicada.');
        }
    }
}
