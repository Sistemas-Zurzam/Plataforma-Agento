<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Infrastructure\PlanillaComplementaria\Export\PlanillaComplementariaExcelExporter;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PlanillaComplementariaController extends Controller
{
    public function __construct(private readonly PlanillaComplementariaService $service,
        private readonly \App\Modules\Nominas\Services\DescansoSemanalComplementariaService $descansos) {}

    public function descansosSemanales(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate(['boleta_ids' => ['required', 'array', 'min:1'], 'boleta_ids.*' => ['required', 'integer', 'distinct']]);
        return response()->json(['data' => $this->descansos->semanas($this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'])]);
    }

    public function reintegrarDescansosSemanales(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate([
            'semanas' => ['required', 'array', 'min:1'],
            'semanas.*.boleta_id' => ['required', 'integer'],
            'semanas.*.semana_inicio' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:1000'],
            'sin_descanso_sustitutorio' => ['accepted'], 'sin_pago_previo' => ['accepted'],
        ]);
        $item = $this->descansos->crear($this->empresa($request, $ciclo), $ciclo, $datos['semanas'], $datos['motivo'], $request->user('api')->id);
        return response()->json(['data' => $this->presentar($item)], 201);
    }

    public function index(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresa($request, $ciclo);
        return response()->json(['data' => $this->service->listar($empresa, $ciclo)->map(fn ($i) => $this->presentar($i))]);
    }

    public function exportarExcel(Request $request, CicloRemunerativo $ciclo): Response
    {
        $empresa = $this->empresa($request, $ciclo);
        $items = $this->service->listar($empresa, $ciclo);
        abort_if($items->isEmpty(), 422, 'El ciclo no tiene planillas complementarias para exportar.');

        $contenido = PlanillaComplementariaExcelExporter::generar($ciclo->loadMissing('empresa'), $items);
        $nombre = sprintf('%s_%s_reintegros.xlsx', Str::slug($empresa->nombre_comercial), $ciclo->fecha_inicio->format('Y_m'));

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }

    public function descuentos(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate(['boleta_ids' => ['required', 'array', 'min:1'], 'boleta_ids.*' => ['required', 'integer', 'distinct']]);
        return response()->json(['data' => $this->service->descuentosReintegrables($this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'])]);
    }

    public function reintegrarDescuentos(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:1000'],
            'descuentos' => ['required', 'array', 'min:1'],
            'descuentos.*.boleta_id' => ['required', 'integer'],
            'descuentos.*.indice' => ['required', 'integer', 'min:-1'],
            'descuentos.*.version' => ['required', 'string', 'size:64'],
            'descuentos.*.monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
        ]);
        $item = $this->service->reintegrarDescuentos($this->empresa($request, $ciclo), $ciclo, $datos['descuentos'], $datos['motivo'], $request->user('api')->id);
        return response()->json(['data' => $this->presentar($item)], 201);
    }

    public function comisiones(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate(['comisiones' => ['required', 'array', 'min:1'], 'comisiones.*.boleta_id' => ['required', 'integer', 'distinct'], 'comisiones.*.monto' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'], 'motivo' => ['required', 'string', 'max:1000']]);
        $item = $this->service->crearComisiones($this->empresa($request, $ciclo), $ciclo, $datos['comisiones'], $datos['motivo'], $request->user('api')->id);
        return response()->json(['data' => $this->presentar($item)], 201);
    }

    public function feriadosDisponibles(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresa($request, $ciclo);
        return response()->json(['data' => $this->service->feriadosDisponibles($empresa, $ciclo)]);
    }

    public function horasExtraPendientes(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate(['boleta_ids' => ['sometimes', 'array'], 'boleta_ids.*' => ['integer', 'distinct']]);
        return response()->json(['data' => $this->service->horasExtraPendientes($this->empresa($request, $ciclo), $ciclo, $datos['boleta_ids'] ?? [])]);
    }

    public function crearConHorasExtra(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $this->validarHorasExtra($request, true);
        $item = $this->service->crearConHorasExtra($this->empresa($request, $ciclo), $ciclo,
            $datos['horas_detectadas'] ?? [], $datos['horas_manuales'] ?? [], $datos['motivo'], $request->user('api')->id);

        return response()->json(['data' => $this->presentar($item)], 201);
    }

    public function colaboradoresPorAsistencia(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        if ($request->boolean('reporte_gerencia')) {
            $datos = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'area_id' => ['nullable', 'integer']]);
            return response()->json(['data' => app(\App\Modules\Nominas\Services\ReporteBonoAsistenciaService::class)
                ->generar($this->empresa($request, $ciclo), $datos['mes'], $datos['area_id'] ?? null)]);
        }
        $datos = $request->validate([
            'dias' => ['required', 'integer', 'min:1'],
            'operador' => ['required', Rule::in(['exacto', 'minimo'])],
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
        ]);

        return response()->json(['data' => $this->service->colaboradoresPorAsistencia(
            $this->empresa($request, $ciclo),
            $ciclo,
            (int) $datos['dias'],
            $datos['operador'],
            (int) $datos['concepto_id'],
        )]);
    }

    public function exportarBonoExcel(Request $request, CicloRemunerativo $ciclo): Response
    {
        $datos = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'area_id' => ['nullable', 'integer'], 'monto_base' => ['required', 'numeric', 'min:0.01', 'max:9999999']]);
        $libro = app(\App\Modules\Nominas\Services\ExcelBonoAsistenciaService::class)
            ->exportar($this->empresa($request, $ciclo), $datos['mes'], (float) $datos['monto_base'], $datos['area_id'] ?? null);
        return response()->streamDownload(function () use ($libro) {
            try { (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output'); }
            finally { $libro->disconnectWorksheets(); }
        }, 'Bono_asistencia_'.$datos['mes'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function importarBonoExcel(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'generar' => ['sometimes', 'boolean'],
            'concepto_id' => ['nullable', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => ['nullable', 'integer'],
            'motivo' => [$request->boolean('generar') ? 'required' : 'nullable', 'string', 'max:255'],
        ]);
        $empresa = $this->empresa($request, $ciclo);
        $service = app(\App\Modules\Nominas\Services\ExcelBonoAsistenciaService::class);
        try {
            if (! $request->boolean('generar')) return response()->json(['data' => $service->validar($empresa, $ciclo, $request->file('archivo')->getRealPath())]);
            $item = $service->generar($empresa, $ciclo, $request->file('archivo')->getRealPath(), $datos['concepto_id'] ?? null,
                $datos['concepto_definicion_id'] ?? null, $datos['motivo'], $request->user('api')->id);
            return response()->json(['data' => $this->presentar($item)], 201);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el Excel. Usa la plantilla .xlsx exportada, sin cambiar su estructura.']);
        }
    }

    public function aplicarBonoPorAsistencia(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        // Mismo criterio que agregarConcepto(): BONIFICACION/BONO_NO_REMUNERATIVO
        // son demasiado genéricos para Tabla 22 sin una clasificación PLAME concreta.
        $conceptoCodigo = ConceptoRemuneracion::find($request->input('concepto_id'))?->codigo;
        $requiereDefinicion = in_array($conceptoCodigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);

        $datos = $request->validate([
            'boleta_ids' => ['required', 'array', 'min:1'],
            'boleta_ids.*' => ['required', 'integer', 'distinct'],
            'dias' => ['required', 'integer', 'min:1'],
            'operador' => ['required', Rule::in(['exacto', 'minimo'])],
            'concepto_id' => ['required', 'integer', 'exists:conceptos_remuneracion,id'],
            'concepto_definicion_id' => [
                $requiereDefinicion ? 'required' : 'prohibited',
                'integer',
                Rule::exists('concepto_definiciones_plame', 'id')->where('concepto_remuneracion_id', $request->input('concepto_id'))->where('activo', true),
            ],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $item = $this->service->aplicarBonoPorAsistencia(
            $this->empresa($request, $ciclo),
            $ciclo,
            $datos['boleta_ids'],
            (int) $datos['dias'],
            $datos['operador'],
            (int) $datos['concepto_id'],
            $datos['concepto_definicion_id'] ?? null,
            (float) $datos['monto'],
            $datos['motivo'],
            $request->user('api')->id,
        );

        return response()->json(['data' => $this->presentar($item)]);
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

    public function regularizarFeriadoHistorico(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $datos = $request->validate([
            'boleta_ids' => ['required', 'array', 'min:1'],
            'boleta_ids.*' => ['integer', 'distinct'],
            'fecha_feriado' => ['required', 'date'],
            'sin_descanso_sustitutorio' => ['accepted'],
            'sin_pago_previo' => ['accepted'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        $item = $this->service->crearRegularizacionFeriadoHistorico(
            $this->empresa($request, $ciclo),
            $ciclo,
            $datos['boleta_ids'],
            $datos['fecha_feriado'],
            $datos['motivo'],
            $request->user('api')->id,
        );

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

    public function colaboradoresDisponibles(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $datos = $request->validate(['busqueda' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->service->colaboradoresDisponibles(
            $this->empresaItem($request, $complementaria),
            $complementaria,
            $datos['busqueda'] ?? null,
        )]);
    }

    public function agregarColaboradores(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $datos = $request->validate([
            'boleta_ids' => ['required', 'array', 'min:1'],
            'boleta_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $item = $this->service->agregarColaboradores(
            $this->empresaItem($request, $complementaria),
            $complementaria,
            $datos['boleta_ids'],
        );

        return response()->json(['data' => $this->presentar($item)]);
    }

    public function agregarHorasExtra(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $datos = $this->validarHorasExtra($request);
        $item = $this->service->agregarHorasExtra($this->empresaItem($request, $complementaria), $complementaria,
            $datos['horas_detectadas'] ?? [], $datos['horas_manuales'] ?? [], $request->user('api')->id);

        return response()->json(['data' => $this->presentar($item)]);
    }

    private function validarHorasExtra(Request $request, bool $conMotivo = false): array
    {
        return $request->validate([
            'motivo' => [$conMotivo ? 'required' : 'sometimes', 'string', 'max:1000'],
            'horas_detectadas' => ['sometimes', 'array'],
            'horas_detectadas.*.hora_extra_id' => ['required', 'integer', 'distinct'],
            'horas_detectadas.*.minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'horas_manuales' => ['sometimes', 'array'],
            'horas_manuales.*.boleta_id' => ['required', 'integer'],
            'horas_manuales.*.fecha' => ['required', 'date'],
            'horas_manuales.*.minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'horas_manuales.*.tasa' => ['required', Rule::in(['25', '35', '100'])],
            'horas_manuales.*.motivo' => ['required', 'string', 'max:255'],
        ]);
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

    public function reabrir(Request $request, PlanillaComplementaria $complementaria): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        $item = $this->service->reabrir(
            $this->empresaItem($request, $complementaria),
            $complementaria,
            $request->user('api')->id,
            $datos['motivo'],
        );

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

    public function exportarBcpMasivo(Request $request, CicloRemunerativo $ciclo): Response
    {
        $datos = $request->validate([
            'complementaria_ids' => ['required', 'array', 'min:1'],
            'complementaria_ids.*' => ['required', 'integer', 'distinct'],
            'cuenta_cargo_id' => ['required', 'integer'], 'fecha_proceso' => ['required', 'date'],
            'subtipo' => ['required', Rule::in(['4', 'X'])],
        ]);
        $empresa = $this->empresa($request, $ciclo);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->whereKey($datos['cuenta_cargo_id'])->where('activo', true)->firstOrFail();
        abort_unless($cuenta->banco?->codigo === 'bcp', 422, 'La cuenta de cargo debe ser BCP.');
        $contenido = $this->service->exportarBcpMasivo($empresa, $datos['complementaria_ids'], $cuenta, $datos['fecha_proceso'], $datos['subtipo']);
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=Windows-1252', 'Content-Disposition' => 'attachment; filename="TELECREDITO_REINTEGROS_'.$ciclo->id.'_'.now()->format('YmdHis').'.txt"']);
    }

    public function exportarBbvaMasivo(Request $request, CicloRemunerativo $ciclo): Response
    {
        $datos = $request->validate([
            'complementaria_ids' => ['required', 'array', 'min:1'],
            'complementaria_ids.*' => ['required', 'integer', 'distinct'],
            'subtipo' => ['required', Rule::in(['4', '5'])],
        ]);
        $empresa = $this->empresa($request, $ciclo);
        $cuenta = EmpresaCuentaBancaria::with('banco')->where('empresa_id', $empresa->id)->where('activo', true)->where('uso', 'haberes')->whereHas('banco', fn ($q) => $q->where('codigo', 'bbva'))->orderByDesc('es_predeterminada')->first();
        abort_unless($cuenta, 422, 'La empresa no tiene una cuenta BBVA activa para haberes.');
        $contenido = $this->service->exportarBbvaMasivo($empresa, $datos['complementaria_ids'], $cuenta, $datos['subtipo']);
        return response($contenido, 200, ['Content-Type' => 'text/plain; charset=Windows-1252', 'Content-Disposition' => 'attachment; filename="BBVA_REINTEGROS_'.$ciclo->id.'_'.now()->format('YmdHis').'.txt"']);
    }

    private function presentar(PlanillaComplementaria $item): array
    {
        $detalles = $item->detalles;
        $elegibilidad = $this->elegibilidadBancaria($detalles);
        $pendientes = $detalles->where('diferencia_neta', '>', 0);

        return [
            'id' => $item->id, 'ciclo_id' => $item->ciclo_id, 'nombre' => $item->nombre,
            'motivo' => $item->motivo, 'estado' => $item->estado,
            'total_a_pagar' => number_format((float) $pendientes->sum('diferencia_neta'), 2, '.', ''),
            'saldo_a_descontar' => number_format(abs((float) $detalles->where('diferencia_neta', '<', 0)->sum('diferencia_neta')), 2, '.', ''),
            // Un reintegro solo es exportable por un canal si TODOS sus
            // colaboradores con diferencia positiva tienen los datos que
            // exige ese canal (cuenta propia del banco, o CCI si es de
            // otro banco) — TelecreditoBcpPagoBuilder/BbvaNetCashDetalleBuilder
            // lanzan una excepción dura si falta uno solo, y esa excepción
            // tumba el archivo completo, no solo a esa persona (más grave
            // aún en la exportación consolidada de varios reintegros).
            'elegible_telecredito' => $pendientes->isNotEmpty() && $pendientes->every(fn ($d) => $elegibilidad[$d->id]['telecredito']),
            'elegible_netcash' => $pendientes->isNotEmpty() && $pendientes->every(fn ($d) => $elegibilidad[$d->id]['netcash']),
            'colaboradores_datos_incompletos' => $pendientes->reject(fn ($d) => $elegibilidad[$d->id]['telecredito'] && $elegibilidad[$d->id]['netcash'])
                ->map(fn ($d) => trim(($d->colaborador?->nombres ?? '').' '.($d->colaborador?->apellidos ?? '')))->values(),
            'detalles' => $detalles->map(fn ($d) => [
                'id' => $d->id, 'colaborador_id' => $d->colaborador_id,
                'colaborador' => trim(($d->colaborador?->nombres ?? '').' '.($d->colaborador?->apellidos ?? '')),
                'neto_original' => $d->neto_original, 'neto_recalculado' => $d->neto_recalculado,
                'diferencia_ingresos' => $d->diferencia_ingresos, 'diferencia_egresos' => $d->diferencia_egresos,
                'diferencia_aportaciones' => $d->diferencia_aportaciones, 'diferencia_neta' => $d->diferencia_neta,
                'conceptos_manuales' => $this->conceptosManuales($d),
                'reintegros_descuentos' => $d->calculo_snapshot['reintegros_descuentos'] ?? [],
                'descansos_semanales' => $d->calculo_snapshot['descansos_semanales'] ?? [],
                'feriado_regularizado' => $d->calculo_snapshot['feriado_regularizado'] ?? null,
                'horas_extra_regularizadas' => $d->calculo_snapshot['horas_extra_regularizadas'] ?? [],
                'bono_asistencia_gerencia' => $d->calculo_snapshot['bono_asistencia_gerencia'] ?? null,
                'elegible_telecredito' => $elegibilidad[$d->id]['telecredito'],
                'elegible_netcash' => $elegibilidad[$d->id]['netcash'],
            ])->values(),
            'aprobado_at' => $item->aprobado_at?->toDateTimeString(), 'pagado_at' => $item->pagado_at?->toDateTimeString(),
            'referencia_pago' => $item->referencia_pago,
        ];
    }

    /** @return array<int, array{telecredito: bool, netcash: bool}> indexado por detalle->id */
    private function elegibilidadBancaria($detalles): array
    {
        $colaboradores = \App\Modules\Personas\Models\Colaborador::withTrashed()
            ->whereIn('id', $detalles->pluck('colaborador_id')->unique())
            ->get(['id', 'banco_id', 'cci'])
            ->keyBy('id');
        $bancoIds = $detalles->pluck('banco_id')->filter()
            ->merge($colaboradores->pluck('banco_id')->filter())->unique();
        $bancos = \App\Modules\Configuracion\Models\Banco::whereIn('id', $bancoIds)->get()->keyBy('id');

        return $detalles->mapWithKeys(function ($d) use ($bancos, $colaboradores) {
            $colaborador = $colaboradores->get($d->colaborador_id);
            $banco = $bancos->get($d->banco_id ?: $colaborador?->banco_id);
            $cci = $d->cci_snapshot ?: $colaborador?->cci;
            $tieneCci = is_string($cci) && preg_match('/^\d{20}$/', $cci) === 1;
            return [$d->id => [
                'telecredito' => $banco?->codigo === 'bcp' || $tieneCci,
                'netcash' => $banco?->codigo === 'bbva' || $tieneCci,
            ]];
        })->all();
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
        $lineasHorasExtra = collect($snapshot['horas_extra_regularizadas'] ?? [])->pluck('linea_id')->filter();

        return collect([
            ...collect($snapshot['ingresos'] ?? [])->map(fn (array $l) => [...$l, 'tipo' => 'ingreso']),
            ...collect($snapshot['egresos'] ?? [])->map(fn (array $l) => [...$l, 'tipo' => 'egreso']),
        ])
            ->filter(fn (array $l) => isset($l['agregado_por']))
            ->reject(fn (array $l) => $lineasHorasExtra->contains($l['id'] ?? null))
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
