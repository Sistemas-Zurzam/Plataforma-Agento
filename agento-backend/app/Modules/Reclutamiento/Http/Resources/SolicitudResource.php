<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'area' => $this->whenLoaded('area', fn () => ['id' => $this->area->id, 'nombre' => $this->area->nombre]),
            'area_id' => $this->area_id,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante ? ['id' => $this->solicitante->id, 'nombre' => $this->solicitante->name] : null),
            'solicitante_user_id' => $this->solicitante_user_id,
            'cargo' => $this->cargo,
            'motivo' => $this->motivo,
            'plazas' => $this->plazas,
            'salario_min' => $this->salario_min,
            'salario_max' => $this->salario_max,
            'moneda' => $this->moneda,
            'tipo_contrato' => $this->tipo_contrato,
            'modalidad' => $this->modalidad,
            'fecha_requerida' => $this->fecha_requerida?->toDateString(),
            'descripcion' => $this->descripcion,
            'actividades' => $this->actividades,
            'justificacion' => $this->justificacion,
            'presupuesto' => $this->presupuesto,
            'urgente' => $this->urgente,
            'estado' => $this->estado,
            'editable' => $this->editable(),
            'enviada_at' => $this->enviada_at?->toIso8601String(),
            'decision_motivo' => $this->decision_motivo,
            'decidida_at' => $this->decidida_at?->toIso8601String(),
            'cancelacion_motivo' => $this->cancelacion_motivo,
            'cancelada_at' => $this->cancelada_at?->toIso8601String(),
            'habilidades' => $this->whenLoaded('habilidades', fn () => $this->habilidades->map->only(['nombre', 'nivel'])->values()),
            'proceso' => $this->whenLoaded('proceso', fn () => $this->proceso ? [
                'id' => $this->proceso->id, 'codigo' => $this->proceso->codigo, 'estado' => $this->proceso->estado,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
