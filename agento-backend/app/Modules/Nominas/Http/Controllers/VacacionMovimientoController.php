<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Http\Requests\StoreVacacionMovimientoRequest;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kardex manual de ajustes vacacionales (Sección "Vacaciones pendientes y
 * truncas" de LiquidacionCeseService): permite a RR.HH. registrar saldos que
 * no se derivan de asistencia (devengo inicial al migrar de otro sistema,
 * goces/pagos ya liquidados fuera de Agento, correcciones puntuales). Solo
 * afecta el cálculo de liquidación por cese — nunca modifica boletas ya
 * calculadas.
 */
class VacacionMovimientoController extends Controller
{
    public function index(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->autorizar($request, $colaborador);

        return response()->json(['data' => $colaborador->vacacionMovimientos()->get()]);
    }

    public function store(StoreVacacionMovimientoRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->autorizar($request, $colaborador);
        if (! $colaborador->activo) {
            throw ValidationException::withMessages(['colaborador' => 'No se pueden registrar ajustes vacacionales de un colaborador cesado.']);
        }

        $movimiento = $colaborador->vacacionMovimientos()->create([
            ...$request->validated(),
            'empresa_id' => $colaborador->empresa_id,
            'registrado_por' => $request->user('api')->id,
        ]);

        return response()->json(['data' => $movimiento], 201);
    }

    public function destroy(Request $request, Colaborador $colaborador, VacacionMovimiento $movimiento): JsonResponse
    {
        $this->autorizar($request, $colaborador);
        if ($movimiento->colaborador_id !== $colaborador->id) {
            abort(404);
        }
        if (! $colaborador->activo) {
            throw ValidationException::withMessages(['colaborador' => 'No se pueden eliminar ajustes vacacionales de un colaborador cesado.']);
        }

        $movimiento->delete();

        return response()->json(['message' => 'Movimiento eliminado correctamente.']);
    }

    private function autorizar(Request $request, Colaborador $colaborador): void
    {
        if ($colaborador->empresa_id !== $request->user('api')->empresa->id) {
            abort(403, 'El colaborador no pertenece a la empresa activa.');
        }
    }
}
