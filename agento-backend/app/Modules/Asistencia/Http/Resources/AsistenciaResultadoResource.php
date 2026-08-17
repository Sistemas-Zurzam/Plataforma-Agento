<?php

namespace App\Modules\Asistencia\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsistenciaResultadoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha?->toDateString(),
            'tipo_dia' => $this->tipo_dia,
            'estado' => $this->estado,
            'entrada' => $this->entrada_at?->format('Y-m-d H:i:s'),
            'salida' => $this->salida_at?->format('Y-m-d H:i:s'),
            'minutos_programados' => $this->minutos_programados,
            'minutos_trabajados' => $this->minutos_trabajados,
            'minutos_tardanza' => $this->minutos_tardanza,
            'minutos_salida_anticipada' => $this->minutos_salida_anticipada,
            'minutos_extra_observados' => $this->minutos_extra_observados,
            'minutos_extra_25' => $this->minutos_extra_25,
            'minutos_extra_35' => $this->minutos_extra_35,
            'minutos_extra_100' => $this->minutos_extra_100,
            'colaborador' => [
                'id' => $this->colaborador->id,
                'legajo' => $this->colaborador->legajo,
                'nombre_completo' => trim($this->colaborador->nombres.' '.$this->colaborador->apellidos),
                'documento' => $this->colaborador->numero_documento,
                'area' => $this->colaborador->area?->nombre,
                'cargo' => $this->colaborador->cargo,
            ],
            'incidencias_pendientes' => $this->incidencias->where('estado', 'pendiente')->count(),
        ];
    }
}
