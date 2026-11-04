<?php

namespace App\Modules\Personas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Personas\Application\SolicitudCeseService;
use App\Modules\Personas\Http\Resources\SolicitudCeseResource;
use App\Modules\Personas\Infrastructure\AlmacenEvidenciasCese;
use App\Modules\Personas\Models\SolicitudCese;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de RR. HH. de solicitudes de cese. La ejecución recibe los mismos
 * campos y reglas que PATCH /colaboradores/{id}/cesar (fecha_cese entre el
 * ingreso y hoy, motivo_cese ≤ 255) y delega en ColaboradorService::cesar().
 */
class SolicitudCeseController extends Controller
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return SolicitudCeseResource::collection(
            $this->base($request)
                ->when($filtros['etapa'] ?? null, fn (Builder $q, string $etapa) => match ($etapa) {
                    'ejecutada' => $q->whereNotNull('ejecutada_at'),
                    'aprobada_pendiente_ejecucion' => $q->where('estado', SolicitudCese::ESTADO_APROBADA)->whereNull('ejecutada_at'),
                    default => $q->where('estado', $etapa),
                })
                ->with(self::RELACIONES)->latest('id')
                ->paginate($filtros['per_page'] ?? 20),
        );
    }

    public function show(Request $request, int $solicitud): SolicitudCeseResource
    {
        return new SolicitudCeseResource($this->encontrar($request, $solicitud)->load(self::RELACIONES));
    }

    public function resolver(Request $request, int $solicitud): SolicitudCeseResource
    {
        $registro = $this->encontrar($request, $solicitud);
        $datos = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'rechazar'])],
            'motivo_rechazo' => ['required_if:accion,rechazar', 'nullable', 'string', 'min:5', 'max:1000'],
            'fecha_cese_aprobada' => ['nullable', 'date_format:Y-m-d'],
            'motivo_cambio_fecha' => ['nullable', 'string', 'max:1000'],
        ]);
        $usuario = $request->user('api');

        $resultado = $datos['accion'] === 'aprobar'
            ? $this->solicitudes->aprobar($usuario->empresa, $registro, $datos['fecha_cese_aprobada'] ?? null, $datos['motivo_cambio_fecha'] ?? null, $usuario)
            : $this->solicitudes->rechazar($usuario->empresa, $registro, $datos['motivo_rechazo'], $usuario);

        return new SolicitudCeseResource($resultado->load(self::RELACIONES));
    }

    public function ejecutar(Request $request, int $solicitud): SolicitudCeseResource
    {
        $registro = $this->encontrar($request, $solicitud)->load('colaborador');
        $ingreso = $registro->colaborador->fecha_ingreso?->toDateString();
        $datos = $request->validate([
            'fecha_cese' => array_filter(['required', 'date', $ingreso ? "after_or_equal:{$ingreso}" : null, 'before_or_equal:today']),
            'motivo_cese' => ['required', 'string', 'max:255'],
        ]);
        $usuario = $request->user('api');

        return new SolicitudCeseResource(
            $this->solicitudes->ejecutar($usuario->empresa, $registro, $datos['fecha_cese'], $datos['motivo_cese'], $usuario)->load(self::RELACIONES),
        );
    }

    public function evidencia(Request $request, int $solicitud, int $evidencia): StreamedResponse
    {
        $registro = $this->encontrar($request, $solicitud)->evidencias()->whereKey($evidencia)->first();
        abort_unless($registro, 404);

        return $this->almacen->descargar($registro);
    }

    private function base(Request $request): Builder
    {
        return SolicitudCese::query()->where('empresa_id', $request->user('api')->empresa_id);
    }

    private function encontrar(Request $request, int $id): SolicitudCese
    {
        return $this->base($request)->whereKey($id)->firstOrFail();
    }
}
