<?php

namespace App\Modules\Configuracion\Services;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de cuentas bancarias de empresa — mismo criterio de autorización
 * que ReglaDescuentoTardanzaService (tieneAccesoA), pero SIN
 * #[ScopedBy(EmpresaScope::class)] en el modelo: Telecrédito necesita
 * resolver la cuenta de cargo de CUALQUIER empresa que el usuario
 * administre (vía el ciclo), no solo la empresa activa de la sesión — un
 * scope global filtraría eso al vuelo (mismo motivo que Boleta/
 * CicloRemunerativo no lo usan).
 */
class EmpresaCuentaBancariaService
{
    /**
     * @return Collection<int, EmpresaCuentaBancaria>
     */
    public function listar(Empresa $empresa, User $usuario): Collection
    {
        $this->autorizar($empresa, $usuario);

        return $empresa->cuentasBancarias()->with('banco')->orderByDesc('es_predeterminada')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(Empresa $empresa, array $datos, User $usuario): EmpresaCuentaBancaria
    {
        $this->autorizar($empresa, $usuario);

        return DB::transaction(function () use ($empresa, $datos) {
            if ($datos['es_predeterminada'] ?? false) {
                $this->desmarcarPredeterminadas($empresa, $datos['uso']);
            }

            return $empresa->cuentasBancarias()->create($datos)->load('banco');
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, EmpresaCuentaBancaria $cuenta, array $datos, User $usuario): EmpresaCuentaBancaria
    {
        $this->autorizar($empresa, $usuario);
        $this->verificarPertenencia($empresa, $cuenta);

        return DB::transaction(function () use ($empresa, $cuenta, $datos) {
            if ($datos['es_predeterminada'] ?? false) {
                $this->desmarcarPredeterminadas($empresa, $datos['uso'] ?? $cuenta->uso, exceptoId: $cuenta->id);
            }

            $cuenta->update($datos);

            return $cuenta->load('banco');
        });
    }

    public function actualizarEstado(Empresa $empresa, EmpresaCuentaBancaria $cuenta, bool $activo, User $usuario): EmpresaCuentaBancaria
    {
        $this->autorizar($empresa, $usuario);
        $this->verificarPertenencia($empresa, $cuenta);

        $cuenta->update(['activo' => $activo, 'es_predeterminada' => $activo ? $cuenta->es_predeterminada : false]);

        return $cuenta->load('banco');
    }

    private function desmarcarPredeterminadas(Empresa $empresa, string $uso, ?int $exceptoId = null): void
    {
        $empresa->cuentasBancarias()
            ->where('uso', $uso)
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->update(['es_predeterminada' => false]);
    }

    private function verificarPertenencia(Empresa $empresa, EmpresaCuentaBancaria $cuenta): void
    {
        abort_unless($cuenta->empresa_id === $empresa->id, 404, 'Esta cuenta bancaria no pertenece a esta empresa.');
    }

    private function autorizar(Empresa $empresa, User $usuario): void
    {
        if (! $usuario->tieneAccesoA($empresa)) {
            throw new AuthorizationException('No tienes acceso a esta empresa.');
        }
    }
}
