<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreComisionAfpRequest;
use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Services\ComisionAfpService;
use Illuminate\Http\JsonResponse;

class ComisionAfpController extends Controller
{
    public function __construct(private readonly ComisionAfpService $comisiones) {}

    public function index(): JsonResponse
    {
        return response()->json($this->comisiones->listar());
    }

    public function store(StoreComisionAfpRequest $request): JsonResponse
    {
        $this->comisiones->crear($request->validated());

        return response()->json($this->comisiones->listar());
    }

    public function destroy(ComisionAfp $comision): JsonResponse
    {
        $this->comisiones->eliminar($comision);

        return response()->json($this->comisiones->listar());
    }

    public function cargarSbs(): JsonResponse
    {
        $this->comisiones->cargarValoresSbs2024();

        return response()->json($this->comisiones->listar());
    }
}
