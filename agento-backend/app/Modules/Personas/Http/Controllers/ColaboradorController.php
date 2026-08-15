<?php

namespace App\Modules\Personas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Personas\Http\Requests\StoreColaboradorRequest;
use App\Modules\Personas\Http\Resources\ColaboradorResource;
use App\Modules\Personas\Services\ColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ColaboradorController extends Controller
{
    public function __construct(private readonly ColaboradorService $colaboradores) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActiva = $request->user('api')->empresa;
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $paginador = $this->colaboradores->listar($empresaActiva, $request->input('busqueda'), $perPage);

        return ColaboradorResource::collection($paginador)
            ->additional(['stats' => $this->colaboradores->estadisticas($empresaActiva)]);
    }

    public function store(StoreColaboradorRequest $request): ColaboradorResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $colaborador = $this->colaboradores->crear($empresaActiva, $request->validated());

        return new ColaboradorResource($colaborador);
    }

    public function calendarioDefecto(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],
            'fecha_ingreso' => ['required', 'date'],
        ]);

        $empresaActiva = $request->user('api')->empresa;
        $horario = Horario::findOrFail($datos['horario_id']);

        return response()->json(
            $this->colaboradores->calendarioPorDefecto($empresaActiva, $horario, $datos['fecha_ingreso']),
        );
    }
}
