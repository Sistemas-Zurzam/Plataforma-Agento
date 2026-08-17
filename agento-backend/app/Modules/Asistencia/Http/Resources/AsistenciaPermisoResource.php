<?php

namespace App\Modules\Asistencia\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaPermisoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim($this->colaborador->nombres.' '.$this->colaborador->apellidos),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
