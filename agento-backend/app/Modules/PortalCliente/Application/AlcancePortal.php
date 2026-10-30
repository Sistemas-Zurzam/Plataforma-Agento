<?php

namespace App\Modules\PortalCliente\Application;

use App\Models\User;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Punto único de autorización de datos del Portal de Empresa.
 *
 * - La empresa sale SIEMPRE del usuario autenticado por JWT (empresa
 *   activa, ya validada por EnsurePortalEmpresaVigente) — nunca del request.
 * - Con portal.alcance_empresa (cliente_empresa, administrador) el alcance
 *   es toda la empresa activa, como el Portal Cliente original.
 * - Sin él (GER): solo colaboradores ACTIVOS de las áreas autorizadas. Las
 *   áreas autorizadas siguen la misma regla real de Selección
 *   (AlcanceSeleccion): users.area_id + áreas donde es responsable. Sin
 *   áreas no hay acceso — nunca se cae a "toda la empresa" por defecto.
 *
 * Todo listado, contador, detalle, archivo u operación masiva del portal
 * debe pasar por colaboradores()/restringir()/asegurarColaborador().
 */
final class AlcancePortal
{
    private const ATRIBUTO_REQUEST = 'alcance_portal';

    /**
     * @param  array<int, string>  $permisos
     * @param  array<int, int>  $areaIds
     */
    private function __construct(
        public readonly User $usuario,
        public readonly Empresa $empresa,
        private readonly bool $esAdministrador,
        private readonly array $permisos,
        public readonly bool $empresaCompleta,
        public readonly array $areaIds,
    ) {}

    public static function de(User $usuario): self
    {
        $rol = $usuario->currentRole();
        $esAdministrador = $rol?->clave === Role::ADMINISTRADOR;
        $permisos = $rol ? $rol->permissions->pluck('clave')->all() : [];
        $empresaCompleta = $esAdministrador || in_array('portal.alcance_empresa', $permisos, true);

        // Con alcance de empresa las áreas no restringen nada: no se consultan.
        $areaIds = $empresaCompleta ? [] : Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $usuario->empresa_id)
            ->where(fn ($query) => $query
                ->where('responsable_user_id', $usuario->id)
                ->when($usuario->area_id, fn ($q) => $q->orWhere('id', $usuario->area_id)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new self($usuario, $usuario->empresa, $esAdministrador, $permisos, $empresaCompleta, $areaIds);
    }

    /**
     * Una sola resolución por request (los permisos y áreas se leen de BD
     * en cada request, nunca del JWT, para que una revocación aplique de
     * inmediato).
     */
    public static function desdeRequest(Request $request): self
    {
        $alcance = $request->attributes->get(self::ATRIBUTO_REQUEST);
        if (! $alcance instanceof self) {
            $alcance = self::de($request->user('api'));
            $request->attributes->set(self::ATRIBUTO_REQUEST, $alcance);
        }

        return $alcance;
    }

    public function puede(string $permiso): bool
    {
        return $this->esAdministrador || in_array($permiso, $this->permisos, true);
    }

    /**
     * Colaboradores visibles/operables. GER: solo activos de sus áreas.
     *
     * @return Builder<Colaborador>
     */
    public function colaboradores(): Builder
    {
        return Colaborador::query()
            ->where('colaboradores.empresa_id', $this->empresa->id)
            ->when(! $this->empresaCompleta, fn (Builder $query) => $query
                ->whereIn('colaboradores.area_id', $this->areaIds ?: [0])
                ->where('colaboradores.activo', true));
    }

    /**
     * Restringe cualquier consulta con columna colaborador_id al alcance.
     * La empresa se filtra siempre, aunque el modelo tenga EmpresaScope.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restringir(Builder $query, string $tabla): Builder
    {
        return $query->where("{$tabla}.empresa_id", $this->empresa->id)
            ->when(! $this->empresaCompleta, fn (Builder $q) => $q
                ->whereIn("{$tabla}.colaborador_id", $this->colaboradores()->select('colaboradores.id')));
    }

    /**
     * Para solicitudes creadas desde el portal (ceses, propuestas): el
     * alcance actual O las que el propio usuario creó. Así quien solicitó
     * un cese sigue viendo su solicitud ejecutada aunque el colaborador ya
     * esté inactivo, sin ampliar el acceso a otros datos de ese colaborador.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restringirConPropias(Builder $query, string $tabla, string $columnaAutor): Builder
    {
        return $query->where("{$tabla}.empresa_id", $this->empresa->id)
            ->when(! $this->empresaCompleta, fn (Builder $q) => $q->where(fn (Builder $sub) => $sub
                ->whereIn("{$tabla}.colaborador_id", $this->colaboradores()->select('colaboradores.id'))
                ->orWhere("{$tabla}.{$columnaAutor}", $this->usuario->id)));
    }

    /**
     * 404 (nunca 403) para no revelar si el id existe en otra empresa/área.
     */
    public function asegurarColaborador(Colaborador|int $colaborador): Colaborador
    {
        $id = $colaborador instanceof Colaborador ? $colaborador->id : $colaborador;
        $encontrado = $this->colaboradores()->whereKey($id)->first();
        abort_unless($encontrado, 404, 'El colaborador no existe o no pertenece a tu equipo.');

        return $encontrado;
    }

    public function puedeVerArea(int $areaId): bool
    {
        if ($this->empresaCompleta) {
            return Area::withoutGlobalScope(EmpresaScope::class)
                ->where('empresa_id', $this->empresa->id)->whereKey($areaId)->exists();
        }

        return in_array($areaId, $this->areaIds, true);
    }

    /**
     * @return Collection<int, array{id: int, nombre: string}>
     */
    public function areasAutorizadas(): Collection
    {
        return Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $this->empresa->id)
            ->when(! $this->empresaCompleta, fn ($q) => $q->whereIn('id', $this->areaIds ?: [0]))
            ->orderBy('nombre')
            ->get(['id', 'nombre'])
            ->map(fn (Area $area) => ['id' => $area->id, 'nombre' => $area->nombre]);
    }

    /**
     * No existe vínculo User↔Colaborador en el modelo de datos. Para impedir
     * que un gerente decida sobre sí mismo se usa, como resguardo
     * conservador, el correo: si EXACTAMENTE un colaborador de la empresa
     * tiene el mismo correo que el usuario, se considera su ficha. Ver
     * docs de entrega: la regla definitiva requiere un vínculo explícito.
     */
    public function colaboradorPropioId(): ?int
    {
        $email = mb_strtolower(trim((string) $this->usuario->email));
        if ($email === '') {
            return null;
        }
        $ids = Colaborador::query()
            ->where('empresa_id', $this->empresa->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    public function asegurarNoEsPropio(Colaborador $colaborador, string $accion): void
    {
        abort_if(
            $this->colaboradorPropioId() === $colaborador->id,
            403,
            "No puedes {$accion} sobre tu propia ficha. Esta gestión corresponde a RR. HH.",
        );
    }
}
