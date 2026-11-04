<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use App\Modules\Reclutamiento\Application\PruebaService;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Requests\PruebaRequest;
use App\Modules\Reclutamiento\Http\Resources\PruebaResource;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\Prueba;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PruebaController extends SeleccionController
{
    public function __construct(
        private readonly PruebaService $pruebas,
        private readonly AplicacionPruebaService $aplicacion,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PruebaResource::collection($this->pruebas->listar($this->alcance($request), $request->only(['estado', 'busqueda']), $this->porPagina($request)));
    }

    public function show(Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->detalle($prueba));
    }

    public function store(PruebaRequest $request): JsonResponse
    {
        return (new PruebaResource($this->pruebas->crear($this->alcance($request), $request->validated())))->response()->setStatusCode(201);
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

    public function archivar(Request $request, Prueba $prueba): PruebaResource
    {
        return new PruebaResource($this->pruebas->archivar($this->alcance($request), $prueba));
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
