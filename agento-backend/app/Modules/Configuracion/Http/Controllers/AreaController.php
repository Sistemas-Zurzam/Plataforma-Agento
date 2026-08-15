<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreAreaRequest;
use App\Modules\Configuracion\Http\Resources\AreaResource;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AreaController extends Controller
{
    /**
     * List the areas of a given empresa. Deliberately bypasses Area's
     * EmpresaScope (which filters by the actor's *active* empresa): this
     * endpoint is used from admin flows (e.g. creating a user) where the
     * target empresa may not be the one currently active, same reasoning
     * as SedeController.
     */
    public function index(Request $request, Empresa $empresa): AnonymousResourceCollection
    {
        $tieneAcceso = $request->user('api')->empresas()
            ->where('empresas.id', $empresa->id)
            ->exists();

        abort_unless($tieneAcceso, 403, 'No tienes acceso a esta empresa.');

        $areas = Area::withoutGlobalScope(EmpresaScope::class)
            ->where('empresa_id', $empresa->id)
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
        $tieneAcceso = $request->user('api')->empresas()
            ->where('empresas.id', $empresa->id)
            ->exists();

        abort_unless($tieneAcceso, 403, 'No tienes acceso a esta empresa.');

        $area = $empresa->areas()->create([
            'nombre' => Str::trim($request->validated('nombre')),
        ]);

        return new AreaResource($area);
    }
}
