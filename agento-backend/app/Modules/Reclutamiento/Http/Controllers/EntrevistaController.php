<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Modules\Reclutamiento\Application\EntrevistaService;
use App\Modules\Reclutamiento\Http\Requests\EntrevistaRequest;
use App\Modules\Reclutamiento\Http\Requests\EvaluarEntrevistaRequest;
use App\Modules\Reclutamiento\Http\Requests\MotivoRequest;
use App\Modules\Reclutamiento\Http\Resources\EntrevistaResource;
use App\Modules\Reclutamiento\Models\Entrevista;
use App\Modules\Reclutamiento\Models\Postulacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class EntrevistaController extends SeleccionController
{
    public function __construct(private readonly EntrevistaService $entrevistas) {}

    /**
     * Entrevistas asignadas al usuario como evaluador (p. ej. gerencia),
     * sin exponer CV ni otros resultados del candidato.
     */
    public function mias(Request $request): AnonymousResourceCollection
    {
        $alcance = $this->alcance($request);

        return EntrevistaResource::collection(
            Entrevista::where('empresa_id', $alcance->empresaId)
                ->where('evaluador_user_id', $alcance->usuario->id)
                ->where('estado', 'programada')
                ->with(['evaluador:id,name', 'postulacion.candidato:id,nombres,apellido_paterno,apellido_materno', 'postulacion.proceso:id,cargo'])
                ->orderBy('programada_at')
                ->get(),
        );
    }

    public function store(EntrevistaRequest $request, Postulacion $postulacion): JsonResponse
    {
        $entrevista = $this->entrevistas->programar($this->alcance($request), $postulacion, $request->validated());

        return (new EntrevistaResource($entrevista))->response()->setStatusCode(201);
    }

    public function cancelar(MotivoRequest $request, Entrevista $entrevista): EntrevistaResource
    {
        $motivo = $request->validated('motivo');
        if (blank($motivo)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la cancelación.']);
        }

        return new EntrevistaResource($this->entrevistas->cancelar($this->alcance($request), $entrevista, $motivo));
    }

    public function evaluar(EvaluarEntrevistaRequest $request, Entrevista $entrevista): JsonResponse
    {
        $evaluacion = $this->entrevistas->evaluar($this->alcance($request), $entrevista, $request->validated());

        return response()->json(['data' => [
            'id' => $evaluacion->id,
            'promedio' => $evaluacion->promedio,
            'puntaje' => $evaluacion->puntaje,
            'recomendacion' => $evaluacion->recomendacion,
        ]], 201);
    }
}
