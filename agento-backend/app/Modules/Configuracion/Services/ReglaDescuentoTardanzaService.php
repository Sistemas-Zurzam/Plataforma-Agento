<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ReglaDescuentoTardanza;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * CRUD simple: cada regla es un rango de minutos de tardanza que resuelve a
 * un tipo de descuento — sin lógica de negocio propia más allá de evitar
 * solapes (StoreReglaDescuentoTardanzaRequest), por eso no necesita
 * Domain/Application separados. El motor que SÍ tiene la regla de negocio
 * (cuál regla aplica y cómo calcula el monto) vive en
 * PlanillaDependienteCalculator::calcularDescuentoTardanza().
 */
class ReglaDescuentoTardanzaService
{
    /**
     * @return Collection<int, ReglaDescuentoTardanza>
     */
    public function listar(Empresa $empresa, User $usuario): Collection
    {
        $this->autorizar($empresa, $usuario);

        return $empresa->reglasDescuentoTardanza;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Empresa $empresa, array $datos, User $usuario): ReglaDescuentoTardanza
    {
        $this->autorizar($empresa, $usuario);

        $orden = ($empresa->reglasDescuentoTardanza()->max('orden') ?? 0) + 1;

        return $empresa->reglasDescuentoTardanza()->create([...$datos, 'orden' => $orden]);
    }

    public function eliminar(Empresa $empresa, ReglaDescuentoTardanza $regla, User $usuario): void
    {
        $this->autorizar($empresa, $usuario);

        abort_unless($regla->empresa_id === $empresa->id, 404, 'Esta regla no pertenece a esta empresa.');

        $regla->delete();
    }

    private function autorizar(Empresa $empresa, User $usuario): void
    {
        if (! $usuario->tieneAccesoA($empresa)) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }
    }
}
