<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Application\PropuestaConceptoService;
use App\Modules\Nominas\Http\Resources\PropuestaConceptoResource;
use App\Modules\Nominas\Models\PropuestaConcepto;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\PortalProponerBonoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PortalBonosController extends Controller
{
    public function __construct(private readonly PropuestaConceptoService $propuestas) {}

    /** Solo ciclos que aceptan conceptos; sin montos ni estados de cálculo. */
    public function ciclos(Request $request): JsonResponse
    {
        $alcance = AlcancePortal::desdeRequest($request);

        return response()->json(['data' => $this->propuestas->ciclosAbiertos($alcance->empresa)->map(fn ($ciclo) => [
            'id' => $ciclo->id,
            'nombre' => $ciclo->nombre,
            'fecha_inicio' => $ciclo->fecha_inicio?->toDateString(),
            'fecha_fin' => $ciclo->fecha_fin?->toDateString(),
        ])->values()]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([PropuestaConcepto::ESTADO_PROPUESTO, PropuestaConcepto::ESTADO_VALIDADO, PropuestaConcepto::ESTADO_RECHAZADO])],
            'ciclo_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $alcance = AlcancePortal::desdeRequest($request);
        $query = $alcance->restringirConPropias(PropuestaConcepto::query(), 'nomina_propuestas_concepto', 'propuesto_por');

        return PropuestaConceptoResource::collection($this->propuestas->listar($query, $filtros, $filtros['per_page'] ?? 15));
    }

    public function store(PortalProponerBonoRequest $request): JsonResponse
    {
        $alcance = AlcancePortal::desdeRequest($request);
        $datos = $request->validated();
        $colaborador = $alcance->asegurarColaborador((int) $datos['colaborador_id']);
        $alcance->asegurarNoEsPropio($colaborador, 'proponer bonos o comisiones');

        $resultado = $this->propuestas->proponer($alcance->empresa, $colaborador, $datos, $alcance->usuario);
        $propuesta = $resultado['propuesta']->load(['colaborador.area', 'ciclo', 'proponente:id,name', 'resolutor:id,name']);

        return (new PropuestaConceptoResource($propuesta))->response()->setStatusCode($resultado['creada'] ? 201 : 200);
    }
}
