<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use App\Modules\Reclutamiento\Application\ImportadorPrueba;
use App\Modules\Reclutamiento\Application\PruebaService;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Requests\PruebaRequest;
use App\Modules\Reclutamiento\Http\Resources\PruebaResource;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaAsignacion;
use App\Modules\Reclutamiento\Models\PruebaImagen;
use App\Modules\Reclutamiento\Models\PruebaIntento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PruebaController extends SeleccionController
{
    public function __construct(
        private readonly PruebaService $pruebas,
        private readonly AplicacionPruebaService $aplicacion,
        private readonly ImportadorPrueba $importador,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PruebaResource::collection($this->pruebas->listar($this->alcance($request), $request->only(['estado', 'busqueda', 'tipo', 'area']), $this->porPagina($request)));
    }

    public function areas(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pruebas->areas($this->alcance($request))]);
    }

    public function show(Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->detalle($prueba));
    }

    public function store(PruebaRequest $request): JsonResponse
    {
        return (new PruebaResource($this->pruebas->crear($this->alcance($request), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Vista previa de un banco de preguntas (Excel/CSV/JSON o texto pegado):
     * no guarda nada. Devuelve el contenido para revisarlo en el editor y
     * las advertencias de lo que hay que completar.
     */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required_without:texto', 'nullable', 'file', 'max:5120', 'extensions:xlsx,xls,csv,json'],
            'texto' => ['required_without:archivo', 'nullable', 'string', 'max:60000'],
            'nombre' => ['nullable', 'string', 'max:150'],
        ]);

        $resultado = $request->hasFile('archivo')
            ? $this->importador->desdeArchivo($request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalExtension())
            : $this->importador->desdeTexto((string) $request->input('texto'), $request->input('nombre'));

        return response()->json(['data' => $resultado]);
    }

    public function plantilla(): StreamedResponse
    {
        $libro = $this->importador->plantilla();

        return response()->streamDownload(
            fn () => IOFactory::createWriter($libro, 'Xlsx')->save('php://output'),
            'plantilla_banco_preguntas.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function subirImagen(Request $request): JsonResponse
    {
        $request->validate(['imagen' => ['required', 'file', 'max:2048', 'mimetypes:'.implode(',', PruebaImagen::MIMES)]]);
        $imagen = $this->pruebas->subirImagen($this->alcance($request), $request->file('imagen'));

        return response()->json(['data' => $imagen->paraRespuesta()], 201);
    }

    /**
     * Imagen por URL firmada temporal (la validan los middlewares de la
     * ruta): sirve en <img> para RR. HH. y para el candidato sin exponer el
     * archivo de forma permanente.
     */
    public function imagen(PruebaImagen $imagen): Response
    {
        abort_unless(Storage::disk($imagen->disco)->exists($imagen->ruta), 404);

        return Storage::disk($imagen->disco)->response($imagen->ruta, null, [
            'Content-Type' => $imagen->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(PruebaRequest $request, Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->actualizar($this->alcance($request), $prueba, $request->validated()));
    }

    public function publicar(Request $request, Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->publicar($this->alcance($request), $prueba));
    }

    public function validar(MotivoRequest $request, Prueba $prueba): PruebaResource
    {
        $fuente = $request->validated('motivo');
        if (blank($fuente) || mb_strlen($fuente) < 20) {
            throw ValidationException::withMessages(['motivo' => 'Describe la fuente o criterio que valida el instrumento (mínimo 20 caracteres).']);
        }

        return new PruebaResource($this->pruebas->validar($this->alcance($request), $prueba, $fuente));
    }

    public function nuevaVersion(Request $request, Prueba $prueba): JsonResponse
    {
        return (new PruebaResource($this->pruebas->nuevaVersion($this->alcance($request), $prueba)))->response()->setStatusCode(201);
    }

    public function reactivar(Request $request, Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->reactivar($this->alcance($request), $prueba));
    }

    public function destroy(Request $request, Prueba $prueba): JsonResponse
    {
        $this->pruebas->eliminar($this->alcance($request), $prueba);

        return response()->json(['data' => ['eliminada' => true]]);
    }

    public function archivar(Request $request, Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->archivar($this->alcance($request), $prueba));
    }

    /**
     * Conclusión del evaluador sobre el resultado de la prueba (hoja de
     * resultados de la guía).
     */
    public function concluir(Request $request, PruebaAsignacion $asignacion): JsonResponse
    {
        $datos = $request->validate([
            'conclusion' => ['required', Rule::in(PruebaIntento::CONCLUSIONES)],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ]);
        $intento = $this->aplicacion->concluir($this->alcance($request), $asignacion, $datos['conclusion'], $datos['comentario'] ?? null);

        return response()->json(['data' => [
            'conclusion' => $intento->conclusion,
            'comentario' => $intento->conclusion_comentario,
            'concluido_at' => $intento->concluido_at?->toIso8601String(),
        ]]);
    }

    public function asignar(Request $request, Postulacion $postulacion, Prueba $prueba): JsonResponse
    {
        $resultado = $this->aplicacion->asignar($this->alcance($request), $postulacion, $prueba);

        return response()->json(['data' => [
            'asignacion_id' => $resultado['asignacion']->id,
            'disponible_hasta' => $resultado['asignacion']->disponible_hasta->toIso8601String(),
            // El enlace se entrega a RR. HH. solo si el correo no salió, para
            // compartirlo por otro canal.
            'enlace' => $resultado['correo_enviado'] ? null : $resultado['enlace'],
            'correo_enviado' => $resultado['correo_enviado'],
        ]], 201);
    }
}
