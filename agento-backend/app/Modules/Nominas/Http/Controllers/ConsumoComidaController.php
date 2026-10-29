<?php

namespace App\Modules\Nominas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\ConsumoComida\ConsumoComidaImportacionService;
use App\Modules\Nominas\Http\Requests\ImportarConsumoComidaRequest;
use App\Modules\Nominas\Infrastructure\ConsumoComida\Export\ConsumoComidaExcelExporter;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConsumoComidaImportacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consumo de comida del ciclo remunerativo. Igual que el resto de "Planilla
 * mensual", la empresa es la DEL CICLO (o de la importación) validada contra
 * las empresas autorizadas del usuario autenticado; nunca se recibe
 * empresa_id del frontend.
 */
class ConsumoComidaController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly ConsumoComidaImportacionService $service) {}

    public function plantilla(): Response
    {
        return $this->descarga(ConsumoComidaExcelExporter::plantilla(), 'plantilla-consumos-comida.xlsx');
    }

    public function index(Request $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaDelCiclo($request, $ciclo);

        $importaciones = ConsumoComidaImportacion::where('ciclo_id', $ciclo->id)
            ->where('empresa_id', $empresa->id)
            ->with(['importadoPor:id,name', 'aplicadoPor:id,name', 'anuladoPor:id,name'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => [
                'resumen' => $this->service->resumenCiclo($empresa, $ciclo),
                'importaciones' => $importaciones,
            ],
        ]);
    }

    public function consumosColaborador(Request $request, CicloRemunerativo $ciclo, int $colaboradorId): JsonResponse
    {
        $empresa = $this->empresaDelCiclo($request, $ciclo);

        return response()->json(['data' => $this->service->consumosDeColaborador($empresa, $ciclo, $colaboradorId)]);
    }

    public function importar(ImportarConsumoComidaRequest $request, CicloRemunerativo $ciclo): JsonResponse
    {
        $empresa = $this->empresaDelCiclo($request, $ciclo);
        $datos = $request->validated();

        $importacion = $this->service->importar(
            $empresa, $ciclo, $request->file('archivo'), $datos['sistema_origen'],
            isset($datos['reemplaza_importacion_id']) ? (int) $datos['reemplaza_importacion_id'] : null,
            $request->user('api')->id,
        );

        return response()->json(['data' => $this->detalle($importacion)], 201);
    }

    public function show(Request $request, ConsumoComidaImportacion $consumoImportacion): JsonResponse
    {
        $this->empresaDeImportacion($request, $consumoImportacion);

        return response()->json(['data' => $this->detalle($consumoImportacion)]);
    }

    public function admitirDuplicados(Request $request, ConsumoComidaImportacion $consumoImportacion): JsonResponse
    {
        $empresa = $this->empresaDeImportacion($request, $consumoImportacion);
        $datos = $request->validate([
            'detalle_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'detalle_ids.*' => ['required', 'integer', 'distinct'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $importacion = $this->service->admitirDuplicados($empresa, $consumoImportacion, $datos['detalle_ids'], $datos['motivo'], $request->user('api')->id);

        return response()->json(['data' => $this->detalle($importacion)]);
    }

    public function aplicar(Request $request, ConsumoComidaImportacion $consumoImportacion): JsonResponse
    {
        $empresa = $this->empresaDeImportacion($request, $consumoImportacion);
        $datos = $request->validate(['confirmar_boletas_aprobadas' => ['sometimes', 'boolean']]);

        $importacion = $this->service->aplicar($empresa, $consumoImportacion, $request->user('api')->id, (bool) ($datos['confirmar_boletas_aprobadas'] ?? false));

        return response()->json(['data' => $this->detalle($importacion)]);
    }

    public function anular(Request $request, ConsumoComidaImportacion $consumoImportacion): JsonResponse
    {
        $empresa = $this->empresaDeImportacion($request, $consumoImportacion);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'confirmar_boletas_aprobadas' => ['sometimes', 'boolean'],
        ]);

        $importacion = $this->service->anular($empresa, $consumoImportacion, $datos['motivo'], $request->user('api')->id, (bool) ($datos['confirmar_boletas_aprobadas'] ?? false));

        return response()->json(['data' => $this->detalle($importacion)]);
    }

    public function archivo(Request $request, ConsumoComidaImportacion $consumoImportacion): StreamedResponse
    {
        $this->empresaDeImportacion($request, $consumoImportacion);
        abort_unless(Storage::disk('local')->exists($consumoImportacion->archivo_ruta), 404, 'El archivo original no está disponible.');

        return Storage::disk('local')->download(
            $consumoImportacion->archivo_ruta,
            Str::ascii($consumoImportacion->archivo_nombre_original),
            ['Content-Type' => self::XLSX],
        );
    }

    public function errores(Request $request, ConsumoComidaImportacion $consumoImportacion): Response
    {
        $this->empresaDeImportacion($request, $consumoImportacion);

        return $this->descarga(ConsumoComidaExcelExporter::errores($consumoImportacion), "errores-consumos-comida-{$consumoImportacion->id}.xlsx");
    }

    /** @return array<string, mixed> */
    private function detalle(ConsumoComidaImportacion $importacion): array
    {
        $importacion->load(['importadoPor:id,name', 'aplicadoPor:id,name', 'anuladoPor:id,name', 'eventos.usuario:id,name']);

        return [
            'importacion' => $importacion,
            'detalles' => $importacion->detalles()->orderBy('fila')->get(),
            'previsualizacion' => $this->service->previsualizacion($importacion),
        ];
    }

    private function empresaDelCiclo(Request $request, CicloRemunerativo $ciclo): Empresa
    {
        abort_unless($request->user('api')->tieneAccesoA($ciclo->empresa), 403, 'No tienes acceso a la empresa de este ciclo remunerativo.');

        return $ciclo->empresa;
    }

    private function empresaDeImportacion(Request $request, ConsumoComidaImportacion $importacion): Empresa
    {
        abort_unless($request->user('api')->tieneAccesoA($importacion->empresa), 403, 'No tienes acceso a la empresa de esta importación.');

        return $importacion->empresa;
    }

    private function descarga(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, [
            'Content-Type' => self::XLSX,
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'Content-Length' => (string) strlen($contenido),
        ]);
    }
}
