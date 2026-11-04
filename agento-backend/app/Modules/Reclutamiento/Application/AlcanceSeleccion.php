<?php

namespace App\Modules\Reclutamiento\Application;

use App\Models\User;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Contexto de autorización de Selección para el usuario autenticado. La
 * empresa sale SIEMPRE del usuario autenticado por JWT (empresa activa),
 * nunca del request. Reutiliza el RBAC existente (rol de la empresa activa +
 * permisos) y las reglas reales de áreas:
 *
 * - Acceso total (ADM/RYS): administrador o quien gestiona solicitudes,
 *   vacantes o candidatos.
 * - GER: solo áreas autorizadas = su área (users.area_id) + las áreas donde
 *   es responsable (areas.responsable_user_id), y sin datos sensibles (CV,
 *   notas de entrevista, psicometría) salvo permiso explícito.
 */
final class AlcanceSeleccion
{
    /**
     * @param  array<int, string>  $permisos
     * @param  array<int, int>  $areaIds
     */
    private function __construct(
        public readonly User $usuario,
        public readonly int $empresaId,
        private readonly bool $esAdministrador,
        private readonly array $permisos,
        public readonly array $areaIds,
    ) {}

    public static function de(User $usuario): self
    {
        $rol = $usuario->currentRole();
        $esAdministrador = $rol?->clave === Role::ADMINISTRADOR;
        $permisos = $rol ? $rol->permissions->pluck('clave')->all() : [];

        $areaIds = Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $usuario->empresa_id)
            ->where(fn ($query) => $query
                ->where('responsable_user_id', $usuario->id)
                ->when($usuario->area_id, fn ($q) => $q->orWhere('id', $usuario->area_id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self($usuario, (int) $usuario->empresa_id, $esAdministrador, $permisos, $areaIds);
    }

    public function puede(string $permiso): bool
    {
        return $this->esAdministrador || in_array($permiso, $this->permisos, true);
    }

    public function accesoTotal(): bool
    {
        return $this->puede('seleccion.solicitudes.decidir')
            || $this->puede('seleccion.vacantes.gestionar')
            || $this->puede('seleccion.candidatos.gestionar');
    }

    /**
     * CV, notas de entrevista, puntajes y resultados psicométricos.
     */
    public function verDatosSensibles(): bool
    {
        return $this->puede('seleccion.candidatos.gestionar') || $this->puede('seleccion.evaluaciones.gestionar');
    }

    public function puedeVerArea(int $areaId): bool
    {
        return $this->accesoTotal() || in_array($areaId, $this->areaIds, true);
    }

    /**
     * @throws AuthorizationException
     */
    public function exigirArea(int $areaId): void
    {
        if (! $this->puedeVerArea($areaId)) {
            throw new AuthorizationException('No tienes acceso a esta área.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function exigir(string $permiso): void
    {
        if (! $this->puede($permiso)) {
            throw new AuthorizationException('No tienes permiso para realizar esta acción.');
        }
    }
}
