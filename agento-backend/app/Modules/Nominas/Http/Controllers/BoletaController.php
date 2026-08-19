<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\BoletaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BoletaController extends Controller
{
    public function __construct(private readonly BoletaService $boletas) {}

    public function index(Request $request, CicloRemunerativo $ciclo): AnonymousResourceCollection
    {
        $empresa = $request->user('api')->empresa;
        $tipo = $request->input('tipo');

        return BoletaResource::collection(
            $this->boletas->listar($empresa, $ciclo, max(1, min((int) $request->input('per_page', 25), 100)), $tipo),
        );
    }

    public function show(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $request->user('api')->empresa;

        return new BoletaResource($this->boletas->ver($empresa, $boleta));
    }

    public function resumen(Request $request, CicloRemunerativo $ciclo)
    {
        $empresa = $request->user('api')->empresa;

        return response()->json($this->boletas->resumen($empresa, $ciclo, $request->input('tipo')));
    }

    public function aprobar(Request $request, Boleta $boleta): BoletaResource
    {
        $empresa = $request->user('api')->empresa;

        return new BoletaResource(
            $this->boletas->aprobar($empresa, $boleta, $request->user('api')->id)->load(['colaborador.empresa', 'conceptos.concepto']),
        );
    }

    public function marcarPagada(Request $request, Boleta $boleta): BoletaResource
    {
        $datos = $request->validate([
            'referencia_pago' => ['required', 'string', 'max:255'],
        ]);

        $empresa = $request->user('api')->empresa;

        return new BoletaResource(
            $this->boletas->marcarPagada($empresa, $boleta, $request->user('api')->id, $datos['referencia_pago'])
                ->load(['colaborador.empresa', 'conceptos.concepto']),
        );
    }
}
