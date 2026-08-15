<?php

namespace App\Modules\Asistencia\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Http\Requests\StoreHorarioRequest;
use App\Modules\Asistencia\Http\Requests\UpdateHorarioRequest;
use App\Modules\Asistencia\Http\Resources\HorarioResource;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Services\HorarioService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HorarioController extends Controller
{
    public function __construct(private readonly HorarioService $horarios) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $empresaActiva = $request->user('api')->empresa;
        $perPage = max(1, min((int) $request->input('per_page', 10), 50));

        $paginador = $this->horarios->listar(
            $empresaActiva,
            $request->input('busqueda'),
            $request->input('estado'),
            $perPage,
        );

        return HorarioResource::collection($paginador)
            ->additional(['stats' => $this->horarios->estadisticas($empresaActiva)]);
    }

    public function store(StoreHorarioRequest $request): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $horario = $this->horarios->crear($empresaActiva, $request->validated());

        return new HorarioResource($horario);
    }

    public function update(UpdateHorarioRequest $request, Horario $horario): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $horario = $this->horarios->actualizar($empresaActiva, $horario, $request->validated());

        return new HorarioResource($horario);
    }

    public function duplicar(Request $request, Horario $horario): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $copia = $this->horarios->duplicar($empresaActiva, $horario);

        return new HorarioResource($copia);
    }

    public function cambiarEstado(Request $request, Horario $horario): HorarioResource
    {
        $empresaActiva = $request->user('api')->empresa;
        $horario = $this->horarios->cambiarEstado($empresaActiva, $horario);

        return new HorarioResource($horario);
    }
}
