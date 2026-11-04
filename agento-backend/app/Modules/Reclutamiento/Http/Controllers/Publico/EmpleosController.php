<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\PortalEmpleosService;
use App\Modules\Reclutamiento\Http\Requests\PostularRequest;
use App\Modules\Reclutamiento\Http\Resources\Publico\PublicacionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmpleosController extends Controller
{
    public function __construct(private readonly PortalEmpleosService $portal) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'cargo' => ['nullable', 'string', 'max:100'],
            'ubicacion' => ['nullable', 'string', 'max:100'],
            'modalidad' => ['nullable', 'in:presencial,remoto,hibrido'],
        ]);

        return PublicacionResource::collection($this->portal->listar($filtros, max(1, min((int) $request->input('per_page', 12), 50))));
    }

    public function show(Request $request, string $codigo): PublicacionResource
    {
        ['proceso' => $proceso] = $this->portal->resolverPublicacion($codigo, $request->query('invitacion'));

        return new PublicacionResource($proceso);
    }

    public function postular(PostularRequest $request, string $codigo): JsonResponse
    {
        $datos = $request->validated();
        $postulacion = $this->portal->postular(
            $codigo,
            $datos['invitacion'] ?? null,
            $request->attributes->get('acceso_candidato'),
            $datos,
            $request->file('cv'),
            $request->ip(),
        );

        return response()->json(['data' => [
            'codigo_seguimiento' => $postulacion->codigo_seguimiento,
            'mensaje' => 'Recibimos tu postulación. Puedes consultar su estado en "Mis postulaciones" con tu correo verificado.',
        ]], 201);
    }
}
