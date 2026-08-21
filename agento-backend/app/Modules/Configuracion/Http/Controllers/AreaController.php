<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreAreaRequest;
use App\Modules\Configuracion\Http\Resources\AreaResource;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Configuracion\Services\EmpresaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AreaController extends Controller
{
    public function __construct(private readonly EmpresaService $empresas) {}

    /**
     * List the areas of a given empresa. Deliberately bypasses Area's
     * EmpresaScope (which filters by the actor's *active* empresa): this
     * endpoint is used from admin flows (e.g. creating a user) where the
     * target empresa may not be the one currently active, same reasoning
     * as SedeController.
     */
    public function index(Request $request, Empresa $empresa): AnonymousResourceCollection
    {
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403, 'No tienes acceso a esta empresa.');

        $areas = Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
            ->with('responsable')
            ->orderBy('nombre')
            ->get();

        return AreaResource::collection($areas);
    }

    /**
     * Create a new area for the given empresa. StoreAreaRequest rejects
     * names that already exist for the empresa (case-insensitively), so the
     * "buscar y agregar" selector in the frontend never ends up with
     * duplicate areas differing only by case.
     */
    public function store(StoreAreaRequest $request, Empresa $empresa): AreaResource
    {
        $this->empresas->autorizarAccion($empresa, $request->user('api'), 'areas.crear');
        abort_unless($empresa->activa, 422, 'No puedes crear áreas en una empresa inactiva.');

        $area = $empresa->areas()->create([
            'nombre' => Str::trim($request->validated('nombre')),
        ]);

        return new AreaResource($area);
    }

    /**
     * Responsable "por defecto" del área — distinto del rastro de
     * aprobación por-solicitud que ya vive en asistencia_solicitudes_area.
     */
    public function asignarResponsable(Request $request, Empresa $empresa, Area $area): AreaResource
    {
        $this->empresas->autorizarAccion($empresa, $request->user('api'), 'areas.crear');
        abort_unless($area->empresa_id === $empresa->id, 404, 'Esta área no pertenece a esta empresa.');

        $datos = $request->validate([
            'responsable_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($datos['responsable_user_id'] ?? null) {
            $esDeLaEmpresa = $empresa->users()->where('users.id', $datos['responsable_user_id'])->exists();
            abort_unless($esDeLaEmpresa, 422, 'El usuario responsable debe pertenecer a esta empresa.');
        }

        $area->update(['responsable_user_id' => $datos['responsable_user_id'] ?? null]);

        return new AreaResource($area->fresh('responsable'));
    }
}
