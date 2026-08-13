<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSedeRequest;
use App\Http\Requests\UpdateSedeRequest;
use App\Http\Resources\SedeResource;
use App\Models\Empresa;
use App\Models\Sede;
use App\Services\SedeService;
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

        return SedeResource::collection($empresa->sedes()->orderBy('codigo')->get());
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
