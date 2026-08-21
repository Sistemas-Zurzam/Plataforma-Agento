<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReglaDescuentoTardanzaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'minutos_desde' => $this->minutos_desde,
            'minutos_hasta' => $this->minutos_hasta,
            'tipo' => $this->tipo,
            'valor' => $this->valor,
            'orden' => $this->orden,
        ];
    }
}
