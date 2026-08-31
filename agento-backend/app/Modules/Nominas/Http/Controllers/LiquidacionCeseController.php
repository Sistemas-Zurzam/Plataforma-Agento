<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Models\LiquidacionCese;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiquidacionCeseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        $liquidaciones = LiquidacionCese::where('empresa_id', $empresa->id)
            ->where('es_version_vigente', true)->with(['colaborador', 'conceptos'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->input('estado')))
            ->when($request->filled('fecha_desde'), fn ($q) => $q->whereDate('fecha_cese', '>=', $request->input('fecha_desde')))
            ->when($request->filled('fecha_hasta'), fn ($q) => $q->whereDate('fecha_cese', '<=', $request->input('fecha_hasta')))
            ->when($request->filled('colaborador'), fn ($q) => $q->whereHas('colaborador', function ($sub) use ($request) {
                $busqueda = $request->input('colaborador');
                // El group() envuelve el OR en su propio paréntesis: sin él,
                // Laravel lo combina al mismo nivel que la condición de la
                // relación (colaboradores.id = ...) y el OR rompe el match
                // por id, exponiendo colaboradores de otros registros.
                $sub->where(fn ($grupo) => $grupo->where('legajo', 'like', "%{$busqueda}%")
                    ->orWhere('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%"));
            }))
            ->orderByDesc('fecha_cese')->paginate(min(50, max(1, (int) $request->input('per_page', 20))));

        return response()->json($liquidaciones);
    }

    public function show(Request $request, LiquidacionCese $liquidacion): JsonResponse
    {
        $this->autorizar($request, $liquidacion);
        return response()->json(['data' => $liquidacion->load(['colaborador', 'conceptos'])]);
    }

    public function aprobar(Request $request, LiquidacionCese $liquidacion): JsonResponse
    {
        $this->autorizar($request, $liquidacion);
        if ($liquidacion->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo una liquidación calculada puede aprobarse.']);
        }
        $liquidacion->update(['estado' => 'aprobada', 'aprobado_por' => $request->user('api')->id, 'aprobado_at' => now()]);
        return response()->json(['data' => $liquidacion->fresh(['colaborador', 'conceptos'])]);
    }

    public function pagar(Request $request, LiquidacionCese $liquidacion): JsonResponse
    {
        $this->autorizar($request, $liquidacion);
        $datos = $request->validate(['referencia_pago' => ['required', 'string', 'max:255']]);
        if ($liquidacion->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'La liquidación debe estar aprobada antes de registrar su pago.']);
        }
        $liquidacion->update(['estado' => 'pagada', 'pagado_por' => $request->user('api')->id, 'pagado_at' => now(), 'referencia_pago' => $datos['referencia_pago']]);
        return response()->json(['data' => $liquidacion->fresh(['colaborador', 'conceptos'])]);
    }

    public function anularYRevertir(Request $request, LiquidacionCese $liquidacion): JsonResponse
    {
        $this->autorizar($request, $liquidacion);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        if ($liquidacion->estado === 'pagada' || $liquidacion->estado === 'anulada') {
            throw ValidationException::withMessages(['estado' => 'Una liquidación pagada o ya anulada no puede revertirse desde este flujo.']);
        }

        DB::transaction(function () use ($request, $liquidacion, $datos) {
            $liquidacion = LiquidacionCese::whereKey($liquidacion->id)->lockForUpdate()->firstOrFail();
            $colaborador = $liquidacion->colaborador()->lockForUpdate()->firstOrFail();
            $liquidacion->update(['estado' => 'anulada', 'es_version_vigente' => false, 'anulado_por' => $request->user('api')->id, 'anulado_at' => now(), 'motivo_anulacion' => $datos['motivo']]);
            $colaborador->update([
                'activo' => true, 'fecha_cese' => null, 'motivo_cese' => null,
                'fecha_fin_contrato' => $colaborador->fecha_fin_contrato?->toDateString() === $liquidacion->fecha_cese->toDateString() ? null : $colaborador->fecha_fin_contrato,
            ]);
            $colaborador->asignacionesHorario()->whereDate('vigencia_hasta', $liquidacion->fecha_cese)->latest('vigencia_desde')->limit(1)->update(['vigencia_hasta' => null]);
        });

        return response()->json(['message' => 'Liquidación anulada y cese revertido correctamente.']);
    }

    private function autorizar(Request $request, LiquidacionCese $liquidacion): void
    {
        if ($liquidacion->empresa_id !== $request->user('api')->empresa->id) {
            abort(403, 'La liquidación no pertenece a la empresa activa.');
        }
    }
}
