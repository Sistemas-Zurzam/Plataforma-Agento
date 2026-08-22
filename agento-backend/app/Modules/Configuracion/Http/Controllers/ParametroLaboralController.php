<?php

namespace App\Modules\Configuracion\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Http\Requests\StoreParametroLaboralValoresRequest;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametroLaboralController extends Controller
{
    public function __construct(private readonly ParametroLaboralService $parametros) {}

    public function index(Request $request): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;

        return response()->json($this->parametros->listar($empresaActiva));
    }

    public function store(StoreParametroLaboralValoresRequest $request): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;
        abort_unless($empresaActiva->activa, 422, 'No puedes modificar una empresa inactiva.');
        $datos = $request->validated();

        $this->parametros->crearValores(
            $empresaActiva,
            $datos['regimen_laboral'],
            $datos['vigencia_desde'],
            $datos['valores'],
            $request->user('api'),
            $datos['motivo'] ?? null,
        );

        return response()->json($this->parametros->listar($empresaActiva));
    }

    public function inicializar(Request $request): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;
        abort_unless($empresaActiva->activa, 422, 'No puedes modificar una empresa inactiva.');

        $this->parametros->inicializarValoresPorDefecto($empresaActiva, $request->user('api'));

        return response()->json($this->parametros->listar($empresaActiva));
    }

    public function historial(Request $request, ParametroLaboralDefinicion $definicion): JsonResponse
    {
        $empresaActiva = $request->user('api')->empresa;
        $regimen = $request->validate([
            'regimen_laboral' => ['required', 'string'],
        ])['regimen_laboral'];

        return response()->json([
            'data' => $this->parametros->historial($empresaActiva, $definicion, $regimen),
        ]);
    }
}
