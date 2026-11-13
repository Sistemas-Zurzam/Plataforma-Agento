<?php

namespace App\Modules\Auditoria\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El listado no trae payload ni cambios (pueden ser pesados); solo el
 * detalle los incluye.
 *
 * @mixin \App\Modules\Auditoria\Models\AuditoriaEvento
 */
class AuditoriaEventoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $atributos = $this->resource->getAttributes();
        $esDetalle = array_key_exists('payload', $atributos);

        return [
            'id' => $this->id,
            'fecha' => $this->created_at?->toIso8601String(),
            'tipo' => $this->tipo,
            'accion' => $this->accion,
            'modulo' => $this->modulo,
            'metodo' => $this->metodo,
            'ruta' => $this->ruta,
            'status_code' => $this->status_code,
            'usuario' => [
                'id' => $this->user_id,
                'nombre' => $this->usuario_nombre,
                'rol' => $this->usuario_rol,
            ],
            'recurso_tipo' => $this->recurso_tipo,
            'recurso_id' => $this->recurso_id,
            'ip' => $this->ip,
            'duracion_ms' => $this->duracion_ms,
            'tiene_cambios' => $esDetalle
                ? $this->cambios !== null
                : (bool) ($atributos['tiene_cambios'] ?? false),
            $this->mergeWhen($esDetalle, fn () => [
                'payload' => $this->payload,
                'cambios' => $this->cambios,
                'user_agent' => $this->user_agent,
            ]),
        ];
    }
}
