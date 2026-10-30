<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personas\Application\SolicitudCeseService;
use App\Modules\Personas\Http\Resources\SolicitudCeseResource;
use App\Modules\Personas\Infrastructure\AlmacenEvidenciasCese;
use App\Modules\Personas\Models\SolicitudCese;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\PortalSolicitarCeseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalCesesController extends Controller
{
    private const RELACIONES = ['colaborador.area', 'solicitante:id,name', 'resolutor:id,name', 'ejecutor:id,name', 'evidencias'];

    public function __construct(
        private readonly SolicitudCeseService $solicitudes,
        private readonly AlmacenEvidenciasCese $almacen,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'etapa' => ['nullable', Rule::in(['enviada', 'aprobada_pendiente_ejecucion', 'ejecutada', 'rechazada'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginado = $this->base($request)
            ->when($filtros['etapa'] ?? null, fn (Builder $q, string $etapa) => match ($etapa) {
                'ejecutada' => $q->whereNotNull('ejecutada_at'),
                'aprobada_pendiente_ejecucion' => $q->where('estado', SolicitudCese::ESTADO_APROBADA)->whereNull('ejecutada_at'),
                default => $q->where('estado', $etapa),
            })
            ->with(self::RELACIONES)
            ->latest('id')
            ->paginate($filtros['per_page'] ?? 15);

        return SolicitudCeseResource::collection($paginado);
    }

    public function show(Request $request, int $solicitud): SolicitudCeseResource
    {
        return new SolicitudCeseResource($this->encontrar($request, $solicitud)->load(self::RELACIONES));
    }

    public function store(PortalSolicitarCeseRequest $request): JsonResponse
    {
        $alcance = AlcancePortal::desdeRequest($request);
        $datos = $request->validated();
        $colaborador = $alcance->asegurarColaborador((int) $datos['colaborador_id']);
        $alcance->asegurarNoEsPropio($colaborador, 'solicitar un cese');

        $solicitud = $this->solicitudes->crear(
            $alcance->empresa, $colaborador,
            ['fecha_cese_propuesta' => $datos['fecha_cese_propuesta'], 'motivo' => $datos['motivo']],
            $request->file('evidencias', []), $alcance->usuario,
        );

        return (new SolicitudCeseResource($solicitud->load(self::RELACIONES)))->response()->setStatusCode(201);
    }

    public function evidencia(Request $request, int $solicitud, int $evidencia): StreamedResponse
    {
        $registro = $this->encontrar($request, $solicitud)->evidencias()->whereKey($evidencia)->first();
        abort_unless($registro, 404);

        return $this->almacen->descargar($registro);
    }

    private function base(Request $request): Builder
    {
        return AlcancePortal::desdeRequest($request)
            ->restringirConPropias(SolicitudCese::query(), 'personas_solicitudes_cese', 'solicitante_user_id');
    }

    private function encontrar(Request $request, int $id): SolicitudCese
    {
        $solicitud = $this->base($request)->whereKey($id)->first();
        abort_unless($solicitud, 404, 'La solicitud no existe o no pertenece a tu ámbito.');

        return $solicitud;
    }
}
