<?php

namespace App\Modules\Asistencia\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'tipo' => $this->tipo, 'origen' => $this->origen,
            'fecha_inicio' => $this->fecha_inicio?->toDateString(), 'fecha_fin' => $this->fecha_fin?->toDateString(),
            'motivo' => $this->motivo, 'medio_recepcion' => $this->medio_recepcion, 'estado' => $this->estado,
            'area' => $this->area?->nombre,
            'colaboradores' => $this->colaboradores->map(fn ($item) => ['id' => $item->id, 'nombre_completo' => trim($item->nombres.' '.$item->apellidos), 'legajo' => $item->legajo]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
