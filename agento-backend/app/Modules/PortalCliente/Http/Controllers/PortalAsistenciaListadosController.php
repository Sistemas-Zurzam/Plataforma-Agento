<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\PortalListadoGeneralRequest;
use App\Modules\PortalCliente\Http\Resources\PortalHoraExtraResource;
use App\Modules\PortalCliente\Http\Resources\PortalIncidenciaResource;
use App\Modules\PortalCliente\Http\Resources\PortalPermisoResource;
use App\Modules\PortalCliente\Services\PortalAsistenciaQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Listados generales (toda la empresa activa) de incidencias/horas
 * extra/permisos — distintos de los mismos recursos "por colaborador" en
 * PortalAsistenciaController.
 */
class PortalAsistenciaListadosController extends Controller
{
    public function __construct(private readonly PortalAsistenciaQueryService $asistencia) {}

    public function incidencias(PortalListadoGeneralRequest $request): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalIncidenciaResource::collection(
            $this->asistencia->incidencias(AlcancePortal::desdeRequest($request), $datos, $datos['per_page'] ?? 15),
        );
    }

    public function horasExtra(PortalListadoGeneralRequest $request): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalHoraExtraResource::collection(
            $this->asistencia->horasExtra(AlcancePortal::desdeRequest($request), $datos, $datos['per_page'] ?? 15),
        );
    }

    public function permisos(PortalListadoGeneralRequest $request): AnonymousResourceCollection
    {
        $datos = $request->validated();

        return PortalPermisoResource::collection(
            $this->asistencia->permisos(AlcancePortal::desdeRequest($request), $datos, $datos['per_page'] ?? 15),
        );
    }
}
