<?php

namespace App\Modules\Reclutamiento\Http\Resources\Publico;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que el candidato ve de su propia postulación: estado público y
 * mensaje público. Nunca notas internas, causas de descarte ni puntajes.
 */
class MiPostulacionResource extends JsonResource
{
    private const ESTADO_PUBLICO = [
        'postulacion' => 'Postulación recibida',
        'filtro_cv' => 'En revisión',
        'psicometrica' => 'En evaluación',
        'entrevista_virtual' => 'Entrevistas',
        'entrevista_presencial' => 'Entrevistas',
        'seleccion' => 'Etapa final',
    ];

    private const PASO_PUBLICO = [
        'postulacion' => 0,
        'filtro_cv' => 1,
        'psicometrica' => 2,
        'entrevista_virtual' => 3,
        'entrevista_presencial' => 3,
        'seleccion' => 4,
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $proceso = $this->proceso;

        return [
            'id' => $this->id,
            'codigo_seguimiento' => $this->codigo_seguimiento,
            'cargo' => $proceso?->cargo,
            'codigo_proceso' => $proceso?->codigo,
            'empresa' => $proceso && ! $proceso->empresa_confidencial ? $proceso->empresa?->nombre_comercial : null,
            'estado_publico' => match ($this->estado) {
                'activa' => self::ESTADO_PUBLICO[$this->etapa] ?? 'En proceso',
                'seleccionada' => 'Etapa final',
                'contratada' => 'Seleccionado',
                'retirada' => 'Postulación retirada',
                'descartada' => 'No continúa',
                'finalizada_por_cancelacion' => 'Proceso cancelado',
                default => 'En proceso',
            },
            'vigente' => in_array($this->estado, ['activa', 'seleccionada'], true),
            // Avance público 0–4 (Recibida, Revisión, Evaluación, Entrevistas,
            // Etapa final); no expone la etapa interna exacta.
            'paso' => match (true) {
                in_array($this->estado, ['seleccionada', 'contratada'], true) => 4,
                default => self::PASO_PUBLICO[$this->etapa] ?? 0,
            },
            'contratado' => $this->estado === 'contratada',
            'mensaje' => in_array($this->estado, ['descartada', 'finalizada_por_cancelacion', 'retirada'], true)
                ? $this->mensaje_publico
                : ($proceso?->estado === 'en_pausa' ? $proceso->mensaje_publico : null),
            'pruebas_pendientes' => $this->relationLoaded('asignacionesPrueba') ? $this->asignacionesPrueba->map(fn ($a) => [
                'asignacion_id' => $a->id,
                'nombre' => $a->prueba?->nombre,
                'tiempo_limite_minutos' => $a->prueba?->tiempo_limite_minutos,
                'estado' => $a->estado,
                'disponible_hasta' => $a->disponible_hasta?->toIso8601String(),
            ])->values() : [],
            'postulada_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
