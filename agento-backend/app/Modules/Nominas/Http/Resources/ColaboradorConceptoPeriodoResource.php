<?php

namespace App\Modules\Nominas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ColaboradorConceptoPeriodoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ciclo_id' => $this->ciclo_id,
            'colaborador_id' => $this->colaborador_id,
            'concepto_id' => $this->concepto_id,
            'codigo' => $this->concepto?->codigo,
            'nombre' => $this->concepto?->nombre,
            'tipo' => $this->concepto?->tipo,
            'concepto_definicion_id' => $this->concepto_definicion_id,
            'concepto_definicion_nombre' => $this->conceptoDefinicion?->nombre,
            'monto' => $this->monto,
            'motivo' => $this->motivo,
            'creado_por' => $this->creado_por,
            'created_at' => $this->created_at,
        ];
    }
}
