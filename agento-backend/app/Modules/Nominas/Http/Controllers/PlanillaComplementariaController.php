<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PlanillaComplementariaController extends Controller
{
    public function __construct(private readonly PlanillaComplementariaService $service) {}

    public function index(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresa($request, $ciclo);
        return response()->json(['data' => $this->service->listar($empresa, $ciclo)->map(fn ($i) => $this->presentar($i))]);
    }

    public function store(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate([
            'boleta_ids' => ['required', 'array', 'min:1'],
            'boleta_ids.*' => ['integer', 'distinct'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);
        try {
            $item = $this->service->crear($this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'], $datos['motivo'], $request->user('api')->id);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['calculo' => $e->getMessage()]);
        }
        return response()->json(['data' => $this->presentar($item)], 201);
    }

    public function agregarConcepto(Request $request, PlanillaComplementariaDetalle $detalle): JsonResponse
    {
        // Misma exigencia que CicloRemunerativoController::registrarConcepto:
        // BONIFICACION/BONO_NO_REMUNERATIVO son demasiado genéricos para
        // Tabla 22 sin una clasificación PLAME concreta.
        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);

        $datos = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $requiereDefinicion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        $item = $this->service->agregarConcepto(
            $this->empresaDetalle($request, $detalle),
            $detalle,
            (int) $datos['concepto_id'],
            $datos['concepto_definicion_id'] ?? null,
            (float) $datos['monto'],
            $datos['motivo'] ?? null,
            $request->user('api')->id,
        );

        return response()->json(['data' => $this->presentar($item)]);
    }

    public function eliminarConcepto(Request $request, PlanillaComplementariaDetalle $detalle, string $lineaId): JsonResponse
    {
        $item = $this->service->eliminarConcepto($this->empresaDetalle($request, $detalle), $detalle, $lineaId);

        return response()->json(['data' => $this->presentar($item)]);
    }

    public function eliminar(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $this->service->eliminar($this->empresaItem($request, $complementaria), $complementaria);

        return response()->json(['data' => null]);
    }

    public function aprobar(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $item = $this->service->aprobar($this->empresaItem($request, $complementaria), $complementaria, $request->user('api')->id);
        return response()->json(['data' => $this->presentar($item)]);
    }

    public function pagar(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $datos = $request->validate(['referencia_pago' => ['required', 'string', 'max:255']]);
        $item = $this->service->marcarPagada($this->empresaItem($request, $complementaria), $complementaria, $request->user('api')->id, $datos['referencia_pago']);
        return response()->json(['data' => $this->presentar($item)]);
    }

    public function exportarBcp(Request $request, PlanillaComplementaria $complementaria): Response
    {
        $datos = $request->validate([
            'cuenta_cargo_id' => ['required', 'integer'], 'fecha_proceso' => ['required', 'date'],
            'subtipo' => ['required', Rule::in(['4', 'X'])],
        ]);
        $empresa = $this->empresaItem($request, $complementaria);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->whereKey($datos['cuenta_cargo_id'])->where('activo', true)->firstOrFail();
        abort_unless($cuenta->banco?->codigo === 'bcp', 422, 'La cuenta de cargo debe ser BCP.');
        $contenido = $this->service->exportarBcp($empresa, $complementaria, $cuenta, $datos['fecha_proceso'], $datos['subtipo']);
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=Windows-1252', 'Content-Disposition' => 'attachment; filename="TELECREDITO_COMPLEMENTARIA_'.$complementaria->id.'.txt"']);
    }

    public function exportarBbva(Request $request, PlanillaComplementaria $complementaria): Response
    {
        $datos = $request->validate(['subtipo' => ['required', Rule::in(['4', '5'])]]);
        $empresa = $this->empresaItem($request, $complementaria);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->where('activo', true)->where('uso', 'haberes')->whereHas('banco', fn ($q) => $q->where('codigo', 'bbva'))->orderByDesc('es_predeterminada')->first();
        abort_unless($cuenta, 422, 'La empresa no tiene una cuenta BBVA activa para haberes.');
        $contenido = $this->service->exportarBbva($empresa, $complementaria, $cuenta, $datos['subtipo']);
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=Windows-1252', 'Content-Disposition' => 'attachment; filename="BBVA_COMPLEMENTARIA_'.$complementaria->id.'.txt"']);
    }

    private function presentar(PlanillaComplementaria $item): array
    {
        $detalles = $item->detalles;
        return [
            'id' => $item->id, 'ciclo_id' => $item->ciclo_id, 'nombre' => $item->nombre,
            'motivo' => $item->motivo, 'estado' => $item->estado,
            'total_a_pagar' => number_format((float) $detalles->where('diferencia_neta', '>', 0)->sum('diferencia_neta'), 2, '.', ''),
            'saldo_a_descontar' => number_format(abs((float) $detalles->where('diferencia_neta', '<', 0)->sum('diferencia_neta')), 2, '.', ''),
            'detalles' => $detalles->map(fn ($d) => [
                'id' => $d->id, 'colaborador_id' => $d->colaborador_id,
                'colaborador' => trim(($d->colaborador?->nombres ?? '').' '.($d->colaborador?->apellidos ?? '')),
                'neto_original' => $d->neto_original, 'neto_recalculado' => $d->neto_recalculado,
                'diferencia_ingresos' => $d->diferencia_ingresos, 'diferencia_egresos' => $d->diferencia_egresos,
                'diferencia_aportaciones' => $d->diferencia_aportaciones, 'diferencia_neta' => $d->diferencia_neta,
                'conceptos_manuales' => $this->conceptosManuales($d),
            ])->values(),
            'aprobado_at' => $item->aprobado_at?->toDateTimeString(), 'pagado_at' => $item->pagado_at?->toDateTimeString(),
            'referencia_pago' => $item->referencia_pago,
        ];
    }

    /**
     * Desglose de las líneas agregadas a mano (bono/comisión/descuento) de
     * un detalle — solo las marcadas con `agregado_por` en calculo_snapshot,
     * nunca las que produjo el motor de cálculo — para que RR.HH. pueda ver
     * qué exactamente compone la diferencia y eliminarlas si se equivocó.
     */
    private function conceptosManuales($detalle): array
    {
        $snapshot = $detalle->calculo_snapshot;

        return collect([
            ...collect($snapshot['ingresos'] ?? [])->map(fn (array $l) => [...$l, 'tipo' => 'ingreso']),
            ...collect($snapshot['egresos'] ?? [])->map(fn (array $l) => [...$l, 'tipo' => 'egreso']),
        ])
            ->filter(fn (array $l) => isset($l['agregado_por']))
            ->map(fn (array $l) => [
                'id' => $l['id'] ?? null,
                'codigo' => $l['codigo'],
                'tipo' => $l['tipo'],
                'monto' => $l['monto'],
                'motivo' => $l['motivo'] ?? null,
                'agregado_en' => $l['agregado_en'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function empresa(Request $request, CicloRemunerativo $ciclo)
    {
        abort_unless($request->user('api')->tieneAccesoA($ciclo->empresa), 403);
        return $ciclo->empresa;
    }

    private function empresaItem(Request $request, PlanillaComplementaria $item)
    {
        abort_unless($request->user('api')->tieneAccesoA($item->empresa), 403);
        return $item->empresa;
    }

    private function empresaDetalle(Request $request, PlanillaComplementariaDetalle $detalle)
    {
        $empresa = $detalle->complementaria->empresa;
        abort_unless($request->user('api')->tieneAccesoA($empresa), 403);
        return $empresa;
    }
}
