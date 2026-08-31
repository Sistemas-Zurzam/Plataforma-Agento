<?php

namespace App\Modules\Nominas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la tabla que el frontend muestra cuando una acción de Nóminas
 * (aprobar boleta, cerrar ciclo) queda bloqueada por incidencias de
 * asistencia pendientes — requiere la incidencia con `colaborador` cargado
 * (nombres, apellidos, legajo).
 */
class IncidenciaPendienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'colaborador' => trim(($this->colaborador?->nombres ?? '').' '.($this->colaborador?->apellidos ?? '')),
            'legajo' => $this->colaborador?->legajo,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha?->toDateString(),
            'descripcion' => $this->descripcion,
        ];
    }
}
