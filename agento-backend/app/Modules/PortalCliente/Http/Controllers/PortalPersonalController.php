<?php

namespace App\Modules\PortalCliente\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use App\Modules\Reclutamiento\Application\SeguimientoSolicitudes;
use App\Modules\Reclutamiento\Application\SolicitudService;
use App\Modules\Reclutamiento\Domain\FlujoSolicitud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Solicitudes de personal en el portal: el listado reutiliza
 * SolicitudService::listar() con AlcanceSeleccion (mismas áreas del
 * gerente) y agrega SOLO seguimiento agregado. Crear, corregir y enviar se
 * hacen con los endpoints de Selección existentes (/api/seleccion/solicitudes),
 * que ya aplican el mismo alcance y validaciones.
 */
class PortalPersonalController extends Controller
{
    public function __construct(
        private readonly SolicitudService $solicitudes,
        private readonly SeguimientoSolicitudes $seguimiento,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in(FlujoSolicitud::ESTADOS)],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $usuario = $request->user('api');
        $paginado = $this->solicitudes->listar(AlcanceSeleccion::de($usuario), $filtros, $filtros['per_page'] ?? 15);
        $avance = $this->seguimiento->porSolicitudes((int) $usuario->empresa_id, $paginado->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $paginado->getCollection()->map(fn ($solicitud) => [
                'id' => $solicitud->id,
                'cargo' => $solicitud->cargo,
                'area' => $solicitud->area?->nombre,
                'plazas' => $solicitud->plazas,
                'urgente' => (bool) $solicitud->urgente,
                'fecha_requerida' => $solicitud->fecha_requerida?->toDateString(),
                'estado' => $solicitud->estado,
                'siguiente_responsable' => match ($solicitud->estado) {
                    'borrador', 'devuelta' => 'solicitante',
                    'enviada', 'aceptada' => 'rrhh',
                    default => null,
                },
                // Respuesta de RR. HH. al devolver/rechazar: es para quien solicitó.
                'respuesta_rrhh' => in_array($solicitud->estado, ['devuelta', 'rechazada'], true) ? $solicitud->decision_motivo : null,
                'editable' => $solicitud->editable() && $solicitud->solicitante_user_id === $usuario->id,
                'solicitante' => $solicitud->solicitante?->name,
                'enviada_at' => $solicitud->enviada_at?->toDateTimeString(),
                'actualizado_at' => $solicitud->updated_at?->toDateTimeString(),
                'seguimiento' => $avance[$solicitud->id] ?? null,
            ])->values(),
            'meta' => [
                'current_page' => $paginado->currentPage(),
                'per_page' => $paginado->perPage(),
                'total' => $paginado->total(),
                'last_page' => $paginado->lastPage(),
            ],
        ]);
    }
}
