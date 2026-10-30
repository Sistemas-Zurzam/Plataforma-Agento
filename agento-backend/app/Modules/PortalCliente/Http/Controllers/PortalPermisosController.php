<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asistencia\Models\TipoAusencia;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Application\SolicitarPermisoPortal;
use App\Modules\PortalCliente\Http\Requests\PortalSolicitarPermisoRequest;
use App\Modules\PortalCliente\Http\Resources\PortalPermisoResource;
use Illuminate\Http\JsonResponse;

class PortalPermisosController extends Controller
{
    public function __construct(private readonly SolicitarPermisoPortal $solicitar) {}

    /**
     * Catálogo del formulario: tipos permitidos en el portal (nombres del
     * catálogo tipos_ausencia) y si el goce es fijo o lo elige quien solicita.
     */
    public function tipos(): JsonResponse
    {
        $catalogo = TipoAusencia::query()->whereIn('codigo', PortalSolicitarPermisoRequest::TIPOS)->get()->keyBy('codigo');

        return response()->json(['data' => collect(PortalSolicitarPermisoRequest::TIPOS)->map(fn (string $codigo) => [
            'codigo' => $codigo,
            'nombre' => $catalogo->get($codigo)?->nombre ?? ucfirst(str_replace('_', ' ', $codigo)),
            'con_goce_fijo' => $catalogo->get($codigo)?->remunerada,
            'requiere_con_goce' => in_array($codigo, ['personal', 'capacitacion'], true),
        ])->values()]);
    }

    public function solicitar(PortalSolicitarPermisoRequest $request): JsonResponse
    {
        $permiso = $this->solicitar->ejecutar(AlcancePortal::desdeRequest($request), $request->validated());

        return (new PortalPermisoResource($permiso->load(['colaborador.area', 'resueltoPor:id,name'])))
            ->response()->setStatusCode(201);
    }
}
