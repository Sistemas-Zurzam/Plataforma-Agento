<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\DashboardService;
use App\Modules\Reclutamiento\Http\Resources\TareaResource;
use App\Modules\Reclutamiento\Models\Tarea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DashboardController extends SeleccionController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'area_id' => ['nullable', 'integer'],
            'reclutador_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->dashboard->resumen($this->alcance($request), $filtros)]);
    }

    /**
     * Tareas de seguimiento (completar ficha, onboarding, reingreso) para
     * quien las atiende en Nóminas/RR. HH.
     */
    public function tareas(Request $request): AnonymousResourceCollection
    {
        $alcance = $this->alcance($request);

        return TareaResource::collection(
            Tarea::where('empresa_id', $alcance->empresaId)
                ->when($request->input('estado', 'pendiente'), fn ($q, $estado) => $q->where('estado', $estado))
                ->with('colaborador:id,legajo,nombres,apellidos')
                ->latest('id')
                ->paginate($this->porPagina($request)),
        );
    }

    public function completarTarea(Request $request, Tarea $tarea): TareaResource
    {
        $tarea->update(['estado' => 'completada', 'completada_por' => $this->alcance($request)->usuario->id, 'completada_at' => now()]);

        return new TareaResource($tarea->load('colaborador'));
    }
}
