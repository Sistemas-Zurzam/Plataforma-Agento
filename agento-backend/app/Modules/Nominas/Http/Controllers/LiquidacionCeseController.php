<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Domain\Liquidacion\CatalogoConceptosLiquidacion;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LiquidacionCeseController extends Controller
{
    public function __construct(private readonly LiquidacionCeseService $liquidaciones) {}

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

    /** Cesados de la empresa activa que todavía no tienen liquidación. */
    public function pendientes(Request $request): JsonResponse
    {
        $pendientes = $this->liquidaciones->pendientes($request->user('api')->empresa)->map(fn (Colaborador $c) => [
            'id' => $c->id, 'nombre' => trim("{$c->nombres} {$c->apellidos}"), 'documento' => $c->numero_documento,
            'legajo' => $c->legajo, 'cargo' => $c->cargo, 'area' => $c->area?->nombre,
            'fecha_ingreso' => $c->fecha_ingreso?->toDateString(), 'fecha_cese' => $c->fecha_cese?->toDateString(),
            'motivo_cese' => $c->motivo_cese,
            // Sin sueldo al cese el sistema no puede calcular solo: RR.HH.
            // tendrá que completarlo al liquidar.
            'falta_sueldo' => ! ColaboradorRemuneracion::where('colaborador_id', $c->id)
                ->whereDate('vigencia_desde', '<=', $c->fecha_cese->toDateString())->exists(),
        ])->values();

        return response()->json(['data' => $pendientes]);
    }

    public function catalogo(): JsonResponse
    {
        return response()->json(['data' => CatalogoConceptosLiquidacion::paraSeleccion()]);
    }

    /**
     * GET: propuesta con los datos del sistema. POST: la misma propuesta
     * recalculada con los datos de entrada que RR.HH. completó o corrigió.
     */
    public function propuesta(Request $request, Colaborador $colaborador): JsonResponse
    {
        $datos = $request->validate($this->reglasDatosCalculo());

        return response()->json(['data' => $this->liquidaciones->propuesta($request->user('api')->empresa, $colaborador, $datos['datos'] ?? [])]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer'],
            'modo' => ['required', Rule::in([LiquidacionCese::MODO_ASISTIDO, LiquidacionCese::MODO_MANUAL])],
            'sustento' => ['nullable', 'string', 'max:2000'],
            'conceptos' => ['required', 'array', 'min:1', 'max:60'],
            'conceptos.*.codigo' => ['required', 'string', 'max:80'],
            'conceptos.*.monto' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'conceptos.*.nombre' => ['nullable', 'string', 'max:160'],
            'conceptos.*.motivo_ajuste' => ['nullable', 'string', 'max:1000'],
            ...$this->reglasDatosCalculo(),
        ]);
        $empresa = $request->user('api')->empresa;
        // El colaborador se resuelve dentro de la empresa activa: un id de
        // otra empresa nunca llega al servicio.
        $colaborador = Colaborador::where('empresa_id', $empresa->id)->findOrFail($datos['colaborador_id']);

        $liquidacion = $this->liquidaciones->registrar(
            $empresa, $colaborador, $datos['modo'], $datos['conceptos'], $datos['sustento'] ?? null, $request->user('api')->id,
            $datos['datos'] ?? [],
        );

        return response()->json(['data' => $liquidacion->load(['colaborador', 'conceptos'])], 201);
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
        $datos = $request->validate([
            'referencia_pago' => ['required', 'string', 'max:255'],
            'fecha_pago' => ['required', 'date', 'before_or_equal:today'],
            'medio_pago' => ['nullable', Rule::in(['transferencia', 'cheque', 'efectivo', 'otro'])],
        ]);
        if ($liquidacion->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'La liquidación debe estar aprobada antes de registrar su pago.']);
        }
        $liquidacion->update([
            'estado' => 'pagada', 'pagado_por' => $request->user('api')->id, 'pagado_at' => now(),
            'referencia_pago' => $datos['referencia_pago'], 'fecha_pago' => $datos['fecha_pago'], 'medio_pago' => $datos['medio_pago'] ?? null,
        ]);
        return response()->json(['data' => $liquidacion->fresh(['colaborador', 'conceptos'])]);
    }

    /**
     * Anula la liquidación sin tocar el cese: el colaborador sigue cesado y
     * vuelve a la lista de pendientes para liquidarse de nuevo. Para revertir
     * el cese se usa "Reactivar" en la ficha del colaborador.
     */
    public function anular(Request $request, LiquidacionCese $liquidacion): JsonResponse
    {
        $this->autorizar($request, $liquidacion);
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($request, $liquidacion, $datos) {
            $bloqueada = LiquidacionCese::whereKey($liquidacion->id)->lockForUpdate()->firstOrFail();
            if (in_array($bloqueada->estado, ['pagada', 'anulada'], true)) {
                throw ValidationException::withMessages(['estado' => 'Una liquidación pagada o ya anulada no puede anularse.']);
            }
            $bloqueada->update([
                'estado' => 'anulada', 'es_version_vigente' => false, 'anulado_por' => $request->user('api')->id,
                'anulado_at' => now(), 'motivo_anulacion' => $datos['motivo'],
            ]);
        });

        return response()->json(['message' => 'Liquidación anulada. El colaborador sigue cesado y puede volver a liquidarse.']);
    }

    /** Datos de entrada que RR.HH. puede completar cuando el sistema no los tiene. */
    private function reglasDatosCalculo(): array
    {
        return [
            'datos' => ['nullable', 'array'],
            'datos.fecha_ingreso' => ['nullable', 'date'],
            'datos.sueldo' => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'datos.asignacion_familiar' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'datos.gratificacion_percibida_cts' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'datos.gratificacion_semestre_pagada' => ['nullable', 'boolean'],
            'datos.dias_vacaciones_gozados' => ['nullable', 'numeric', 'min:-9999', 'max:9999'],
        ];
    }

    private function autorizar(Request $request, LiquidacionCese $liquidacion): void
    {
        if ($liquidacion->empresa_id !== $request->user('api')->empresa->id) {
            abort(403, 'La liquidación no pertenece a la empresa activa.');
        }
    }
}
