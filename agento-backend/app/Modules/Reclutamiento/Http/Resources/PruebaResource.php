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
            // "agento": banco único de Agento; "empresa": propia de la empresa.
            'origen' => $this->empresa_id === null ? 'agento' : 'empresa',
            'version' => $this->version,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'tipo' => $this->tipo,
            'area_conocimiento' => $this->area_conocimiento,
            'tiempo_limite_minutos' => $this->tiempo_limite_minutos,
            'estado' => $this->estado,
            'validada' => $this->validada,
            'validacion_fuente' => $this->validacion_fuente,
            'validada_at' => $this->validada_at?->toIso8601String(),
            'preguntas_count' => $this->whenCounted('preguntas'),
            'usos' => $this->whenCounted('asignaciones'),
            'dimensiones' => $this->whenLoaded('dimensiones', fn () => $this->dimensiones->map(fn ($d) => [
                'clave' => (string) $d->id,
                'id' => $d->id,
                'codigo' => $d->codigo,
                'nombre' => $d->nombre,
                'peso' => $d->peso,
                'es_validez' => $d->es_validez,
                'nivel_medio_desde' => $d->nivel_medio_desde,
                'nivel_alto_desde' => $d->nivel_alto_desde,
                'alerta_desde' => $d->alerta_desde,
                'interpretacion_alta' => $d->interpretacion_alta,
                'interpretacion_baja' => $d->interpretacion_baja,
                'pregunta_entrevista' => $d->pregunta_entrevista,
            ])),
            'perfiles_puesto' => $this->whenLoaded('perfilesPuesto', fn () => $this->perfilesPuesto->map(fn ($perfil) => [
                'nombre' => $perfil->nombre,
                'dimensiones' => $perfil->dimensiones->pluck('codigo')->values(),
            ])),
            'escala' => $this->whenLoaded('escala', fn () => $this->escala->map(fn ($e) => ['valor' => $e->valor, 'etiqueta' => $e->etiqueta])),
            'preguntas' => $this->whenLoaded('preguntas', fn () => $this->preguntas->map(fn ($p) => [
                'id' => $p->id,
                'tipo' => $p->tipo,
                'invertida' => $p->invertida,
                'dimension' => (string) $p->dimension_id,
                'enunciado' => $p->enunciado,
                'imagen_id' => $p->imagen_id,
                'imagen_url' => $p->relationLoaded('imagen') ? $p->imagen?->url() : null,
                'peso' => $p->peso,
                'opciones' => $p->opciones->map(fn ($o) => [
                    'id' => $o->id,
                    'texto' => $o->texto,
                    'imagen_id' => $o->imagen_id,
                    'imagen_url' => $o->relationLoaded('imagen') ? $o->imagen?->url() : null,
                    'valor' => $o->valor,
                ]),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
