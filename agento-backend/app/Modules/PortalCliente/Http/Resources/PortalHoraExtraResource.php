<?php

namespace App\Modules\PortalCliente\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal. Omite deliberadamente "motivo" (la nota que deja
 * quien aprueba/rechaza, ver AsistenciaDecisionService::resolverHoraExtra)
 * y resuelto_por — son la decisión privada de RR.HH., no información para
 * el cliente.
 */
class PortalHoraExtraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha?->toDateString(),
            'minutos_observados' => $this->minutos_observados,
            'minutos_solicitados' => $this->minutos_solicitados,
            'minutos_aprobados' => $this->minutos_aprobados,
            'tasa' => $this->tasa,
            'estado' => $this->estado,
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
        ];
    }
}
