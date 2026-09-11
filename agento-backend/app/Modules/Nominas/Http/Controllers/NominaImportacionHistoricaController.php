<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nominas\Http\Requests\ConfirmarCtsDepositadaRequest;
use App\Modules\Nominas\Http\Requests\ConfirmarGratificacionPagadaRequest;
use App\Modules\Nominas\Http\Requests\CorregirDetalleImportacionHistoricaRequest;
use App\Modules\Nominas\Http\Requests\MarcarIgnoradoRequest;
use App\Modules\Nominas\Http\Requests\NominaImportacionHistoricaRequest;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Services\AplicarImportacionHistoricaService;
use App\Modules\Nominas\Services\ImportarAntecedentesHistoricosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NominaImportacionHistoricaController extends Controller
{
    public function importar(NominaImportacionHistoricaRequest $request, ImportarAntecedentesHistoricosService $servicio): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        $importacion = $servicio->importar(
            $empresa, $request->file('archivo'), $request->validated('fecha_corte'), $request->user('api')->id,
        );

        return response()->json(['data' => $importacion], 201);
    }

    /**
     * Vista previa paginada — nunca devuelve las ~4,000 filas en una sola
     * respuesta. Los contadores del bloque `resumen` siempre se derivan en
     * vivo de `nomina_importacion_historica_detalles` (mismo criterio que
     * `BoletaService::resumen()`: nunca un valor guardado aparte que pueda
     * desincronizarse), no de las columnas `filas_*` del lote, que solo
     * reflejan el estado justo al terminar `importar()`.
     */
    public function show(Request $request, NominaImportacionHistorica $importacion): JsonResponse
    {
        $this->autorizarLote($request, $importacion);

        $detalles = NominaImportacionHistoricaDetalle::where('importacion_id', $importacion->id)
            ->when($request->filled('clasificacion'), fn ($q) => $q->where('clasificacion', $request->input('clasificacion')))
            ->when($request->filled('estado_validacion'), fn ($q) => $q->where('estado_validacion', $request->input('estado_validacion')))
            ->when($request->boolean('solo_errores'), fn ($q) => $q->where('estado_validacion', 'error'))
            ->when($request->boolean('solo_observados'), fn ($q) => $q->where('estado_validacion', 'observado'))
            ->when($request->filled('numero_documento'), fn ($q) => $q->where('numero_documento_normalizado', 'like', '%'.$request->input('numero_documento').'%'))
            ->when($request->filled('concepto'), fn ($q) => $q->where('nombre_concepto_original', 'like', '%'.$request->input('concepto').'%'))
            ->orderBy('hoja_nombre')->orderBy('fila_numero')
            ->paginate(min(100, max(1, (int) $request->input('per_page', 50))));

        $base = NominaImportacionHistoricaDetalle::where('importacion_id', $importacion->id);

        return response()->json([
            'data' => $importacion,
            'detalles' => $detalles,
            'resumen' => [
                'filas_totales' => (clone $base)->count(),
                'filas_validas' => (clone $base)->where('clasificacion', 'aplicable')->where('estado_validacion', 'valido')->count(),
                'filas_observadas' => (clone $base)->where('estado_validacion', 'observado')->count(),
                'filas_con_errores' => (clone $base)->where('estado_validacion', 'error')->count(),
                'filas_aplicadas' => (clone $base)->where('estado_validacion', 'aplicado')->count(),
                'filas_ignoradas' => (clone $base)->where('estado_validacion', 'ignorado')->count(),
                'filas_otra_empresa' => $importacion->filas_otra_empresa,
            ],
        ]);
    }

    public function aprobar(Request $request, NominaImportacionHistorica $importacion, ImportarAntecedentesHistoricosService $servicio): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        $importacion = $servicio->aprobar($empresa, $importacion, $request->user('api')->id);

        return response()->json(['data' => $importacion]);
    }

    public function aplicar(Request $request, NominaImportacionHistorica $importacion, AplicarImportacionHistoricaService $servicio): JsonResponse
    {
        $empresa = $request->user('api')->empresa;
        $importacion = $servicio->aplicar($empresa, $importacion, $request->user('api')->id);

        return response()->json(['data' => $importacion]);
    }

    public function corregirDetalle(
        CorregirDetalleImportacionHistoricaRequest $request, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        ImportarAntecedentesHistoricosService $servicio,
    ): JsonResponse {
        $empresa = $request->user('api')->empresa;
        $detalle = $servicio->corregir(
            $empresa, $importacion, $detalle, $request->validated('cambios'), $request->validated('motivo'), $request->user('api')->id,
        );

        return response()->json(['data' => $detalle]);
    }

    public function confirmarCtsDepositada(
        ConfirmarCtsDepositadaRequest $request, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        ImportarAntecedentesHistoricosService $servicio,
    ): JsonResponse {
        $empresa = $request->user('api')->empresa;
        $detalle = $servicio->confirmarCtsDepositada(
            $empresa, $importacion, $detalle,
            $request->validated('referencia_deposito'), Carbon::parse($request->validated('fecha_deposito')),
            $request->validated('motivo'), $request->user('api')->id,
        );

        return response()->json(['data' => $detalle]);
    }

    public function confirmarGratificacionPagada(
        ConfirmarGratificacionPagadaRequest $request, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        ImportarAntecedentesHistoricosService $servicio,
    ): JsonResponse {
        $empresa = $request->user('api')->empresa;
        $detalle = $servicio->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle,
            Carbon::parse($request->validated('fecha_pago')), $request->validated('referencia_pago'),
            $request->validated('motivo'), $request->user('api')->id,
        );

        return response()->json(['data' => $detalle]);
    }

    public function marcarIgnorado(
        MarcarIgnoradoRequest $request, NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle,
        ImportarAntecedentesHistoricosService $servicio,
    ): JsonResponse {
        $empresa = $request->user('api')->empresa;
        $detalle = $servicio->marcarIgnorado($empresa, $importacion, $detalle, $request->validated('motivo'), $request->user('api')->id);

        return response()->json(['data' => $detalle]);
    }

    private function autorizarLote(Request $request, NominaImportacionHistorica $importacion): void
    {
        if ($importacion->empresa_id !== $request->user('api')->empresa->id) {
            abort(403, 'El lote no pertenece a la empresa activa.');
        }
    }
}
