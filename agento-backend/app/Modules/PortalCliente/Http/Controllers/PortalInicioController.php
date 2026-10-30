<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Application\MesaDeTrabajoPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalInicioController extends Controller
{
    public function __construct(private readonly MesaDeTrabajoPortal $mesa) {}

    public function inicio(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mesa->resumen(AlcancePortal::desdeRequest($request))]);
    }
}
