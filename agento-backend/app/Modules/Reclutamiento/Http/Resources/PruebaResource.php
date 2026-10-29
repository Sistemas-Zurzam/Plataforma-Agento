<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista interna (RR. HH.) de una prueba: incluye la clave de calificación
 * (valor de cada opción). Nunca usar en endpoints públicos.
 */
class PruebaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'familia' => $this->familia,
            'version' => $this->version,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'tipo' => $this->tipo,
            'tiempo_limite_minutos' => $this->tiempo_limite_minutos,
            'estado' => $this->estado,
            'validada' => $this->validada,
            'validacion_fuente' => $this->validacion_fuente,
            'validada_at' => $this->validada_at?->toIso8601String(),
            'preguntas_count' => $this->whenCounted('preguntas'),
            'dimensiones' => $this->whenLoaded('dimensiones', fn () => $this->dimensiones->map(fn ($d) => [
                'clave' => (string) $d->id, 'id' => $d->id, 'nombre' => $d->nombre, 'peso' => $d->peso,
            ])),
            'preguntas' => $this->whenLoaded('preguntas', fn () => $this->preguntas->map(fn ($p) => [
                'id' => $p->id,
                'dimension' => (string) $p->dimension_id,
                'enunciado' => $p->enunciado,
                'peso' => $p->peso,
                'opciones' => $p->opciones->map(fn ($o) => ['id' => $o->id, 'texto' => $o->texto, 'valor' => $o->valor]),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
