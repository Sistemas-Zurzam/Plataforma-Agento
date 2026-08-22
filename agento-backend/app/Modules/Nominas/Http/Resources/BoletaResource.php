<?php

namespace App\Modules\Nominas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoletaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ciclo_id' => $this->ciclo_id,
            'colaborador' => [
                'id' => $this->colaborador?->id,
                'nombre_completo' => trim(($this->colaborador?->nombres ?? '').' '.($this->colaborador?->apellidos ?? '')),
                'legajo' => $this->colaborador?->legajo,
                'cargo' => $this->colaborador?->cargo,
                'empresa' => $this->colaborador?->empresa?->nombre,
            ],
            'version' => $this->version,
            'regimen_laboral' => $this->regimen_laboral_snapshot,
            'sueldo_basico' => $this->sueldo_basico_snapshot,
            'dias_pagados' => $this->dias_pagados,
            'asistencia_procesada' => $this->asistencia_procesada,
            'dias_falta' => $this->dias_falta,
            'minutos_tardanza' => $this->minutos_tardanza,
            'total_ingresos' => $this->total_ingresos,
            'total_egresos' => $this->total_egresos,
            'total_aportaciones' => $this->total_aportaciones,
            'neto_a_pagar' => $this->neto_a_pagar,
            'estado' => $this->estado,
            'snapshot_parametros_version' => $this->snapshot_parametros_version,
            'snapshot_reglas_version' => $this->snapshot_reglas_version,
            'alertas' => $this->alertas ?? [],
            'motivo_recalculo' => $this->motivo_recalculo,
            'calculado_at' => $this->calculado_at?->toDateTimeString(),
            'aprobado_at' => $this->aprobado_at?->toDateTimeString(),
            'pagado_at' => $this->pagado_at?->toDateTimeString(),
            'referencia_pago' => $this->referencia_pago,
            'conceptos' => BoletaConceptoResource::collection($this->whenLoaded('conceptos')),
        ];
    }
}
