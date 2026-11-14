<?php

namespace App\Modules\Reclutamiento\Http\Resources\Publico;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Asignación de prueba vista por el candidato: enunciados y opciones SIN
 * valores ni dimensiones; nunca puntajes ni resultados.
 */
class PruebaCandidatoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $intento = $this->intento;
        $enCurso = $intento && ! $intento->finalizado_at;

        return [
            'nombre' => $this->prueba->nombre,
            'descripcion' => $this->prueba->descripcion,
            'tiempo_limite_minutos' => $this->prueba->tiempo_limite_minutos,
            'validada' => $this->prueba->validada,
            'estado' => $this->estado,
            'disponible_hasta' => $this->disponible_hasta?->toIso8601String(),
            'intento' => $intento ? [
                'iniciado_at' => $intento->iniciado_at?->toIso8601String(),
                'vence_at' => $intento->vence_at?->toIso8601String(),
                'finalizado' => $intento->finalizado_at !== null,
                'segundos_restantes' => $enCurso ? max(0, (int) now()->diffInSeconds($intento->vence_at, false)) : 0,
            ] : null,
            // Sin "invertida" ni valores: no deben insinuar la clave.
            'preguntas' => $enCurso ? $this->prueba->preguntas->map(fn ($p) => [
                'id' => $p->id,
                'tipo' => $p->tipo,
                'enunciado' => $p->enunciado,
                'imagen_url' => $p->imagen?->url(),
                'opciones' => $p->opciones->map(fn ($o) => ['id' => $o->id, 'texto' => $o->texto, 'imagen_url' => $o->imagen?->url()])->values(),
            ])->values() : [],
            'respuestas' => $enCurso ? $intento->respuestas->pluck('opcion_id', 'pregunta_id') : (object) [],
        ];
    }
}
