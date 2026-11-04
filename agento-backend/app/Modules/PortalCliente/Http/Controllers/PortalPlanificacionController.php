<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Services\PlanificacionRotativaService;
use App\Modules\Personas\Support\FeriadosPeru;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Application\PlanificarEquipoPortal;
use App\Modules\PortalCliente\Http\Requests\PortalGuardarPlanificacionRequest;
use App\Modules\PortalCliente\Http\Requests\PortalPlanificacionSemanaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PortalPlanificacionController extends Controller
{
    public function __construct(
        private readonly PlanificacionRotativaService $planificacion,
        private readonly PlanificarEquipoPortal $planificar,
    ) {}

    /**
     * Misma consulta semanal de Asistencias › Planificación, limitada al
     * alcance del gerente (y opcionalmente a un área autorizada).
     */
    public function semana(PortalPlanificacionSemanaRequest $request): JsonResponse
    {
        $alcance = AlcancePortal::desdeRequest($request);
        $datos = $request->validated();
        $areaId = $datos['area_id'] ?? null;

        $resultado = $this->planificacion->consultarSemana(
            $alcance->empresa, $datos['semana'], $datos['busqueda'] ?? null,
            (bool) ($datos['solo_rotativos'] ?? true), null,
            fn ($query) => $query
                ->whereIn('colaboradores.id', $alcance->colaboradores()->select('colaboradores.id'))
                ->when($areaId, fn ($q) => $q->where('colaboradores.area_id', $areaId)),
        );

        $feriados = [];
        for ($fecha = Carbon::parse($resultado['semana']['desde']); $fecha->lte($resultado['semana']['hasta']); $fecha->addDay()) {
            if (FeriadosPeru::esFeriado($fecha->toDateString())) {
                $feriados[] = $fecha->toDateString();
            }
        }

        return response()->json(['data' => $resultado + ['feriados' => $feriados]]);
    }

    public function guardar(PortalGuardarPlanificacionRequest $request): JsonResponse
    {
        $resultado = $this->planificar->ejecutar(AlcancePortal::desdeRequest($request), $request->validated()['cambios']);

        return response()->json(['data' => $resultado, 'message' => "Se guardaron {$resultado['aplicados']} cambio(s) de planificación."]);
    }
}
