<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\SolicitudService;
use App\Modules\Reclutamiento\Http\Requests\DecidirSolicitudRequest;
use App\Modules\Reclutamiento\Http\Requests\GuardarSolicitudRequest;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Resources\SolicitudResource;
use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SolicitudController extends SeleccionController
{
    public function __construct(private readonly SolicitudService $solicitudes) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SolicitudResource::collection(
            $this->solicitudes->listar($this->alcance($request), $request->only(['estado', 'area_id', 'busqueda']), $this->porPagina($request)),
        );
    }

    public function referencias(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->solicitudes->referencias($this->alcance($request))]);
    }

    public function show(Request $request, SolicitudPersonal $solicitud): SolicitudResource
    {
        $this->solicitudes->autorizarLectura($this->alcance($request), $solicitud);

        return new SolicitudResource($solicitud->load(['area', 'habilidades', 'solicitante', 'proceso']));
    }

    public function store(GuardarSolicitudRequest $request): JsonResponse
    {
        $solicitud = $this->solicitudes->crear($this->alcance($request), $request->validated());

        return (new SolicitudResource($solicitud))->response()->setStatusCode(201);
    }

    public function update(GuardarSolicitudRequest $request, SolicitudPersonal $solicitud): SolicitudResource
    {
        return new SolicitudResource($this->solicitudes->actualizar($this->alcance($request), $solicitud, $request->validated()));
    }

    public function enviar(Request $request, SolicitudPersonal $solicitud): SolicitudResource
    {
        return new SolicitudResource($this->solicitudes->enviar($this->alcance($request), $solicitud));
    }

    public function decidir(DecidirSolicitudRequest $request, SolicitudPersonal $solicitud): SolicitudResource
    {
        $datos = $request->validated();

        return new SolicitudResource($this->solicitudes->decidir(
            $this->alcance($request), $solicitud, $datos['accion'], $datos['motivo'] ?? null, $datos['reclutador_user_id'] ?? null,
        ));
    }

    public function impactoCancelacion(Request $request, SolicitudPersonal $solicitud): JsonResponse
    {
        $this->solicitudes->autorizarLectura($this->alcance($request), $solicitud);

        return response()->json(['data' => $this->solicitudes->impactoCancelacion($solicitud)]);
    }

    public function cancelar(MotivoRequest $request, SolicitudPersonal $solicitud): JsonResponse
    {
        $resultado = $this->solicitudes->cancelar($this->alcance($request), $solicitud, $request->validated('motivo'));

        return (new SolicitudResource($resultado['solicitud']))->additional(['impacto' => $resultado['impacto']])->response();
    }
}
