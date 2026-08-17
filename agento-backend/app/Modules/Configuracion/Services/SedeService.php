<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SedeService
{
    public function __construct(private readonly EmpresaService $empresas) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Empresa $empresa, array $datos, User $usuario): Sede
    {
        $this->empresas->autorizarAccion($empresa, $usuario, 'sedes.crear');
        $this->verificarEmpresaActiva($empresa);

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
        $this->empresas->autorizarAccion($empresa, $usuario, 'sedes.editar');
        $this->verificarPertenencia($empresa, $sede);

        $sede->update($datos);

        return $sede;
    }

    private function verificarPertenencia(Empresa $empresa, Sede $sede): void
    {
        if ($sede->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Esta sede no pertenece a la empresa indicada.');
        }
    }

    private function verificarEmpresaActiva(Empresa $empresa): void
    {
        if (! $empresa->activa) {
            throw new AuthorizationException('No puedes crear sedes en una empresa inactiva.');
        }
    }
}
