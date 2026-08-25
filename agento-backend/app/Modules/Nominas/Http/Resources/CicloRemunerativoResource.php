<?php

namespace App\Modules\Nominas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CicloRemunerativoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empresa_id' => $this->empresa_id,
            'nombre' => $this->nombre,
            'periodicidad' => $this->periodicidad,
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'fecha_corte_asistencia' => $this->fecha_corte_asistencia?->toDateString(),
            'fecha_pago' => $this->fecha_pago?->toDateString(),
            'estado' => $this->estado,
            'calculo_estado' => $this->calculo_estado,
            'boletas_count' => $this->when(isset($this->boletas_count), $this->boletas_count),
            'boletas_pendientes_aprobacion_count' => $this->when(isset($this->boletas_pendientes_aprobacion_count), $this->boletas_pendientes_aprobacion_count),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
