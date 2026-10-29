<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\EvaluacionAtsService;
use App\Modules\Reclutamiento\Application\PostulacionService;
use App\Modules\Reclutamiento\Application\ProcesoService;
use App\Modules\Reclutamiento\Http\Requests\AccionMasivaRequest;
use App\Modules\Reclutamiento\Http\Requests\DescartarPostulacionRequest;
use App\Modules\Reclutamiento\Http\Requests\EvaluacionAtsRequest;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Requests\MoverPostulacionRequest;
use App\Modules\Reclutamiento\Http\Requests\NoAplicaRequest;
use App\Modules\Reclutamiento\Http\Resources\PostulacionDetalleResource;
use App\Modules\Reclutamiento\Http\Resources\PostulacionResource;
use App\Modules\Reclutamiento\Infrastructure\AlmacenDocumentosCandidato;
use App\Modules\Reclutamiento\Models\Postulacion;
use App\Modules\Reclutamiento\Models\ProcesoSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PostulacionController extends SeleccionController
{
    public function __construct(
        private readonly PostulacionService $postulaciones,
        private readonly ProcesoService $procesos,
        private readonly EvaluacionAtsService $ats,
        private readonly AlmacenDocumentosCandidato $documentos,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PostulacionResource::collection($this->postulaciones->listar(
            $this->alcance($request), $request->only(['proceso_id', 'etapa', 'estado', 'fuente', 'busqueda']), $this->porPagina($request),
        ));
    }

    public function tablero(Request $request, ProcesoSeleccion $proceso): JsonResponse
    {
        $alcance = $this->alcance($request);
        $this->procesos->autorizarLectura($alcance, $proceso);
        $tablero = $this->postulaciones->tablero($alcance, $proceso, $request->only(['busqueda', 'fuente']));

        return response()->json(['data' => collect($tablero['columnas'])->map(fn ($c) => [
            'etapa' => $c['etapa'],
            'total' => $c['total'],
            'postulaciones' => PostulacionResource::collection($c['postulaciones'])->resolve($request),
        ])]);
    }

    public function show(Request $request, Postulacion $postulacion): PostulacionDetalleResource
    {
        $this->alcance($request);

        return new PostulacionDetalleResource($postulacion->load([
            'candidato', 'proceso', 'cv', 'evaluacionAtsVigente', 'puntajeFinalVigente', 'plaza',
            'evaluacionesAts.evaluador:id,name',
            'entrevistas.evaluador:id,name', 'entrevistas.evaluaciones.evaluador:id,name', 'entrevistas.evaluaciones.calificaciones',
            'asignacionesPrueba.prueba', 'asignacionesPrueba.intento.resultados.dimension',
            'movimientos.actor:id,name',
        ]));
    }

    /**
     * CV desde almacenamiento privado, solo con permiso y dentro de la empresa
     * (el binding de {postulacion} ya aplica el scope de empresa).
     */
    public function cv(Request $request, Postulacion $postulacion): StreamedResponse
    {
        $documento = $postulacion->cv()->firstOrFail();

        return $this->documentos->descargar($documento);
    }

    public function mover(MoverPostulacionRequest $request, Postulacion $postulacion): PostulacionResource
    {
        $datos = $request->validated();

        return $this->respuesta($request, $this->postulaciones->mover($this->alcance($request), $postulacion, $datos['etapa'], $datos['motivo'] ?? null));
    }

    public function seleccionar(MotivoRequest $request, Postulacion $postulacion): PostulacionResource
    {
        return $this->respuesta($request, $this->postulaciones->seleccionar($this->alcance($request), $postulacion, $request->validated('motivo')));
    }

    public function descartar(DescartarPostulacionRequest $request, Postulacion $postulacion): PostulacionResource
    {
        $datos = $request->validated();

        return $this->respuesta($request, $this->postulaciones->descartar(
            $this->alcance($request), $postulacion, $datos['causa'], $datos['motivo'], $datos['mensaje_publico'] ?? null,
        ));
    }

    public function reconsiderar(MotivoRequest $request, Postulacion $postulacion): PostulacionResource
    {
        return $this->respuesta($request, $this->postulaciones->reconsiderar($this->alcance($request), $postulacion, (string) $request->validated('motivo')));
    }

    public function retirar(MotivoRequest $request, Postulacion $postulacion): PostulacionResource
    {
        $motivo = $request->validated('motivo');
        if (blank($motivo)) {
            throw ValidationException::withMessages(['motivo' => 'Indica cómo comunicó el candidato su retiro.']);
        }

        return $this->respuesta($request, $this->postulaciones->retirar($postulacion, $motivo, $this->alcance($request)->usuario->id));
    }

    public function noAplica(NoAplicaRequest $request, Postulacion $postulacion): PostulacionResource
    {
        $datos = $request->validated();

        return $this->respuesta($request, $this->postulaciones->marcarNoAplica(
            $this->alcance($request), $postulacion, $datos['componente'], (bool) $datos['no_aplica'], $datos['motivo'],
        ));
    }

    public function evaluarAts(EvaluacionAtsRequest $request, Postulacion $postulacion): PostulacionResource
    {
        $datos = $request->validated();
        $this->ats->evaluarManual($postulacion, $datos['requisitos'] ?? [], $datos['competencias'] ?? [], $this->alcance($request)->usuario->id, $datos['comentario'] ?? null);

        return $this->respuesta($request, $postulacion->refresh());
    }

    public function accionMasiva(AccionMasivaRequest $request): JsonResponse
    {
        $datos = $request->validated();

        return response()->json(['data' => $this->postulaciones->accionMasiva($this->alcance($request), $datos['ids'], $datos['accion'], $datos)]);
    }

    private function respuesta(Request $request, Postulacion $postulacion): PostulacionResource
    {
        $this->alcance($request);

        return new PostulacionResource($postulacion->load(['candidato', 'proceso', 'evaluacionAtsVigente', 'puntajeFinalVigente']));
    }
}
