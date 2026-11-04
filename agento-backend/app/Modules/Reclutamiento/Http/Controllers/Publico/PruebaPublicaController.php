<?php

namespace App\Modules\Reclutamiento\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\AplicacionPruebaService;
use App\Modules\Reclutamiento\Http\Resources\Publico\PruebaCandidatoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rendir una prueba con el token de la asignación. Nunca expone claves,
 * dimensiones, puntajes ni resultados.
 */
class PruebaPublicaController extends Controller
{
    public function __construct(private readonly AplicacionPruebaService $pruebas) {}

    public function show(string $token): PruebaCandidatoResource
    {
        return new PruebaCandidatoResource($this->cargar($token));
    }

    public function iniciar(string $token): PruebaCandidatoResource
    {
        $this->pruebas->iniciar($token);

        return new PruebaCandidatoResource($this->cargar($token));
    }

    public function responder(Request $request, string $token): JsonResponse
    {
        $datos = $request->validate([
            'pregunta_id' => ['required', 'integer'],
            'opcion_id' => ['required', 'integer'],
        ]);

        return response()->json(['data' => $this->pruebas->responder($token, $datos['pregunta_id'], $datos['opcion_id'])]);
    }

    public function finalizar(string $token): JsonResponse
    {
        $this->pruebas->finalizar($token);

        return response()->json(['data' => ['finalizada' => true, 'mensaje' => 'Gracias. Tus respuestas fueron registradas.']]);
    }

    private function cargar(string $token)
    {
        return $this->pruebas->porToken($token)->load(['prueba.preguntas.opciones', 'intento.respuestas']);
    }
}
