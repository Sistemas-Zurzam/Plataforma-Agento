<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreSedeRequest;
use App\Modules\Configuracion\Http\Requests\UpdateSedeRequest;
use App\Modules\Configuracion\Http\Resources\SedeResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Configuracion\Services\SedeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SedeController extends Controller
{
    public function __construct(private readonly SedeService $sedes) {}

    public function index(Request $request, Empresa $empresa): AnonymousResourceCollection
    {
        $tieneAcceso = $request->user('api')->empresas()
            ->where('empresas.id', $empresa->id)
            ->exists();

        abort_unless($tieneAcceso, 403, 'No tienes acceso a esta empresa.');

        $sedes = $empresa->sedes()
            ->when($request->boolean('solo_activas'), fn ($query) => $query->where('activa', true))
            ->orderBy('codigo')
            ->get();

        return SedeResource::collection($sedes);
    }

    public function store(StoreSedeRequest $request, Empresa $empresa): SedeResource
    {
        $sede = $this->sedes->crear($empresa, $request->validated(), $request->user('api'));

        return new SedeResource($sede);
    }

    public function update(UpdateSedeRequest $request, Empresa $empresa, Sede $sede): SedeResource
    {
        $sede = $this->sedes->actualizar(
            $empresa,
            $sede,
            $request->validated(),
            $request->user('api'),
        );

        return new SedeResource($sede);
    }
}
