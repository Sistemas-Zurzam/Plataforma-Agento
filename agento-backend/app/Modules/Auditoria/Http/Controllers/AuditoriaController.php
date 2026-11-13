<?php

namespace App\Modules\Auditoria\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auditoria\Application\RegistradorAuditoria;
use App\Modules\Auditoria\Http\Requests\ListarEventosRequest;
use App\Modules\Auditoria\Http\Requests\StoreEventosUiRequest;
use App\Modules\Auditoria\Http\Resources\AuditoriaEventoResource;
use App\Modules\Auditoria\Models\AuditoriaEvento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Todas las consultas quedan acotadas a la empresa activa del usuario por
 * el EmpresaScope del modelo (el cliente no puede elegir la empresa).
 */
class AuditoriaController extends Controller
{
    public function __construct(private readonly RegistradorAuditoria $registrador) {}

    public function index(ListarEventosRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();

        $eventos = AuditoriaEvento::query()
            ->select([
                'id', 'user_id', 'usuario_nombre', 'usuario_rol', 'tipo', 'accion', 'modulo', 'metodo',
                'ruta', 'status_code', 'recurso_tipo', 'recurso_id', 'ip', 'duracion_ms', 'created_at',
            ])
            ->selectRaw('(cambios IS NOT NULL) as tiene_cambios')
            ->when($f['usuario_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($f['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($f['metodo'] ?? null, fn ($q, $v) => $q->where('metodo', $v))
            ->when(($f['resultado'] ?? null) === 'error', fn ($q) => $q->where('status_code', '>=', 400))
            ->when(($f['resultado'] ?? null) === 'exitoso', fn ($q) => $q->where(
                fn ($w) => $w->whereNull('status_code')->orWhere('status_code', '<', 400),
            ))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v.' 00:00:00'))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('accion', 'like', $like)
                    ->orWhere('usuario_nombre', 'like', $like)
                    ->orWhere('ruta', 'like', $like)
                    ->orWhere('recurso_id', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return AuditoriaEventoResource::collection($eventos);
    }

    public function show(int $evento): AuditoriaEventoResource
    {
        return new AuditoriaEventoResource(AuditoriaEvento::findOrFail($evento));
    }

    /**
     * Valores disponibles para los filtros de la pantalla.
     */
    public function filtros(): JsonResponse
    {
        $usuarios = AuditoriaEvento::query()
            ->whereNotNull('user_id')
            ->select('user_id', 'usuario_nombre')
            ->distinct()
            ->orderBy('usuario_nombre')
            ->get()
            ->map(fn ($e) => ['id' => $e->user_id, 'nombre' => $e->usuario_nombre]);

        $modulos = AuditoriaEvento::query()
            ->whereNotNull('modulo')
            ->distinct()
            ->orderBy('modulo')
            ->pluck('modulo');

        return response()->json(['data' => ['usuarios' => $usuarios, 'modulos' => $modulos]]);
    }

    /**
     * Recibe en lote los clicks y la navegación del frontend.
     */
    public function registrarEventosUi(StoreEventosUiRequest $request): JsonResponse
    {
        $registrados = $this->registrador->eventosUi(
            $request->user('api'),
            $request,
            $request->validated('eventos'),
        );

        return response()->json(['registrados' => $registrados], 201);
    }
}
