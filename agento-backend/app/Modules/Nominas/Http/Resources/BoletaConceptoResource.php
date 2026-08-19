<?php

namespace App\Modules\Nominas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoletaConceptoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->concepto?->codigo,
            'nombre' => $this->concepto?->nombre,
            'tipo' => $this->tipo,
            'es_remunerativo_laboral' => $this->es_remunerativo_laboral,
            'afecta_renta_5ta' => $this->afecta_renta_5ta,
            'afecta_afp' => $this->concepto?->afecta_afp,
            'afecta_essalud' => $this->concepto?->afecta_essalud,
            'afecta_cts' => $this->concepto?->afecta_cts,
            'afecta_gratificacion' => $this->concepto?->afecta_gratificacion,
            'afecta_vacaciones' => $this->concepto?->afecta_vacaciones,
            'base_utilizada' => $this->base_utilizada,
            'tasa_aplicada' => $this->tasa_aplicada,
            'cantidad' => $this->cantidad,
            'monto' => $this->monto,
            'formula_texto' => $this->formula_texto,
        ];
    }
}
