<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreReglaDescuentoTardanzaRequest;
use App\Modules\Configuracion\Http\Resources\ReglaDescuentoTardanzaResource;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ReglaDescuentoTardanza;
use App\Modules\Configuracion\Services\ReglaDescuentoTardanzaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReglaDescuentoTardanzaController extends Controller
{
    public function __construct(private readonly ReglaDescuentoTardanzaService $reglas) {}

    public function index(Request $request, Empresa $empresa): AnonymousResourceCollection
    {
        return ReglaDescuentoTardanzaResource::collection(
            $this->reglas->listar($empresa, $request->user('api')),
        );
    }

    public function store(StoreReglaDescuentoTardanzaRequest $request, Empresa $empresa): ReglaDescuentoTardanzaResource
    {
        $regla = $this->reglas->crear($empresa, $request->validated(), $request->user('api'));

        return new ReglaDescuentoTardanzaResource($regla);
    }

    public function destroy(Request $request, Empresa $empresa, ReglaDescuentoTardanza $regla): JsonResponse
    {
        $this->reglas->eliminar($empresa, $regla, $request->user('api'));

        return response()->json(['message' => 'Regla eliminada correctamente.']);
    }
}
