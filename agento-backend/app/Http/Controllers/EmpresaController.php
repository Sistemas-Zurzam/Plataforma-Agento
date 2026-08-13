<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmpresaRequest;
use App\Http\Requests\UpdateEmpresaRequest;
use App\Http\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Services\EmpresaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmpresaController extends Controller
{
    public function __construct(private readonly EmpresaService $empresas) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresas = $request->user('api')->empresas()->orderBy('nombre')->get();

        return EmpresaResource::collection($empresas);
    }

    public function store(StoreEmpresaRequest $request): EmpresaResource
    {
        $empresa = $this->empresas->crearParaUsuario(
            $request->validated(),
            $request->user('api'),
        );

        return new EmpresaResource($empresa);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): EmpresaResource
    {
        $empresa = $this->empresas->actualizar(
            $empresa,
            $request->validated(),
            $request->user('api'),
        );

        return new EmpresaResource($empresa);
    }

    public function activar(Request $request, Empresa $empresa): JsonResponse
    {
        $this->empresas->activarParaUsuario($empresa, $request->user('api'));

        return response()->json([
            'message' => 'Empresa activa actualizada',
        ]);
    }
}
