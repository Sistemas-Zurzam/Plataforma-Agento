<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpresaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'abreviatura' => $this->abreviatura,
            'grupo' => $this->grupo,
            'ruc' => $this->ruc,
            'direccion' => $this->direccion,
            'color' => $this->color,
            'regimen_laboral' => $this->regimen_laboral,
            'inscrita_remype' => $this->inscrita_remype,
            'activa' => $this->activa,
            'role' => $this->pivot?->role?->clave,
            'es_activa' => $this->id === $request->user('api')?->empresa_id,
        ];
    }
}
