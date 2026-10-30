<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\PortalEquipoMensualRequest;
use App\Modules\PortalCliente\Http\Requests\PortalListadoColaboradorRequest;
use App\Modules\PortalCliente\Http\Requests\PortalListarColaboradoresRequest;
use App\Modules\PortalCliente\Http\Requests\PortalRangoDiasRequest;
use App\Modules\PortalCliente\Http\Requests\PortalResumenRequest;
use App\Modules\PortalCliente\Http\Resources\PortalColaboradorResource;
use App\Modules\PortalCliente\Http\Resources\PortalHoraExtraResource;
use App\Modules\PortalCliente\Http\Resources\PortalIncidenciaResource;
use App\Modules\PortalCliente\Http\Resources\PortalPermisoResource;
use App\Modules\PortalCliente\Services\PortalAsistenciaQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalAsistenciaController extends Controller
{
    public function __construct(private readonly PortalAsistenciaQueryService $asistencia) {}

    public function resumen(PortalResumenRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json([
            'data' => $this->asistencia->resumen(AlcancePortal::desdeRequest($request), $datos['fecha_desde'], $datos['fecha_hasta']),
        ]);
    }

    public function colaboradores(PortalListarColaboradoresRequest $request): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalColaboradorResource::collection(
            $this->asistencia->listarColaboradores(AlcancePortal::desdeRequest($request), $datos, $datos['per_page'] ?? 15),
        );
    }

    public function colaborador(PortalResumenRequest $request, Colaborador $colaborador): JsonResource
    {
        $datos = $request->validated();
        $resultado = $this->asistencia->perfilResumen(
            AlcancePortal::desdeRequest($request), $colaborador, $datos['fecha_desde'], $datos['fecha_hasta'],
        );

        return (new PortalColaboradorResource($resultado['colaborador']))
            ->additional(['metricas' => $resultado['metricas']]);
    }

    public function calendario(PortalRangoDiasRequest $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validated();

        return response()->json([
            'data' => $this->asistencia->calendario(
                AlcancePortal::desdeRequest($request), $colaborador, $datos['fecha_desde'], $datos['fecha_hasta'],
            ),
        ]);
    }

    public function marcaciones(PortalRangoDiasRequest $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validated();

        return response()->json([
            'data' => $this->asistencia->marcaciones(
                AlcancePortal::desdeRequest($request), $colaborador, $datos['fecha_desde'], $datos['fecha_hasta'],
            ),
        ]);
    }

    public function incidencias(PortalListadoColaboradorRequest $request, Colaborador $colaborador): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalIncidenciaResource::collection(
            $this->asistencia->incidenciasDeColaborador(AlcancePortal::desdeRequest($request), $colaborador, $datos, $datos['per_page'] ?? 15),
        );
    }

    public function horasExtra(PortalListadoColaboradorRequest $request, Colaborador $colaborador): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalHoraExtraResource::collection(
            $this->asistencia->horasExtraDeColaborador(AlcancePortal::desdeRequest($request), $colaborador, $datos, $datos['per_page'] ?? 15),
        );
    }

    public function permisos(PortalListadoColaboradorRequest $request, Colaborador $colaborador): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalPermisoResource::collection(
            $this->asistencia->permisosDeColaborador(AlcancePortal::desdeRequest($request), $colaborador, $datos, $datos['per_page'] ?? 15),
        );
    }

    /**
     * Calendario mensual del equipo: colaboradores en filas (paginados) y
     * días en columnas, solo lectura.
     */
    public function equipoMensual(PortalEquipoMensualRequest $request): JsonResponse
    {
        $datos = $request->validated();
        $resultado = $this->asistencia->equipoMensual(AlcancePortal::desdeRequest($request), $datos['mes'], $datos, $datos['per_page'] ?? 15);
        $paginado = $resultado['colaboradores'];

        return response()->json([
            'data' => $paginado->items(),
            'dias' => $resultado['dias'],
            'mes' => $resultado['mes'],
            'meta' => [
                'current_page' => $paginado->currentPage(),
                'per_page' => $paginado->perPage(),
                'total' => $paginado->total(),
                'last_page' => $paginado->lastPage(),
            ],
        ]);
    }

    public function historial(PortalRangoDiasRequest $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validated();
        $paginado = $this->asistencia->historial(
            AlcancePortal::desdeRequest($request), $colaborador, $datos['fecha_desde'], $datos['fecha_hasta'], $datos['per_page'] ?? 15,
        );

        return response()->json([
            'data' => $paginado->items(),
            'meta' => [
                'current_page' => $paginado->currentPage(),
                'per_page' => $paginado->perPage(),
                'total' => $paginado->total(),
                'last_page' => $paginado->lastPage(),
            ],
        ]);
    }
}
