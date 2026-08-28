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
                'empresa' => $this->colaborador?->empresa?->nombre_comercial,
                // Necesarios para precargar ConfiguracionNominaModal desde
                // Planilla mensual — sin estos, el modal siempre mostraba sus
                // valores por defecto (ej. la suspensión de renta de 4ta
                // aparecía desmarcada aunque estuviera guardada).
                'regimen_laboral' => $this->colaborador?->regimen_laboral,
                'sistema_previsional' => $this->colaborador?->sistema_previsional,
                'afp_id' => $this->colaborador?->afp_id,
                'tipo_comision' => $this->colaborador?->tipo_comision,
                'cuspp' => $this->colaborador?->cuspp,
                'tiene_hijos_asignacion_familiar' => $this->colaborador?->tiene_hijos_asignacion_familiar,
                'tiene_suspension_renta_4ta' => $this->colaborador?->tiene_suspension_renta_4ta,
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
            'comprobante_rh' => $this->whenLoaded('comprobanteRh', fn () => $this->comprobanteRh ? [
                // Se resuelve sin ambigüedad desde el concepto ya calculado
                // (HONORARIO_BRUTO) — nunca se duplica como columna propia
                // en boleta_comprobantes_rh (ver BoletaService::montoTotalServicioRh).
                'monto_total_servicio' => $this->whenLoaded('conceptos', fn () => (float) $this->conceptos
                    ->filter(fn ($c) => $c->concepto?->codigo === 'HONORARIO_BRUTO')
                    ->sum('monto')),
                'tipo_comprobante' => $this->comprobanteRh->tipo_comprobante,
                'serie' => $this->comprobanteRh->serie,
                'numero' => $this->comprobanteRh->numero,
                'fecha_emision' => $this->comprobanteRh->fecha_emision?->toDateString(),
                'fecha_pago' => $this->comprobanteRh->fecha_pago?->toDateString(),
                'indicador_retencion_4ta' => $this->comprobanteRh->indicador_retencion_4ta,
                'indicador_retencion_regimen_pensionario' => $this->comprobanteRh->indicador_retencion_regimen_pensionario,
                'importe_aporte_regimen_pensionario' => $this->comprobanteRh->importe_aporte_regimen_pensionario,
            ] : null),
            'conceptos' => BoletaConceptoResource::collection($this->whenLoaded('conceptos')),
        ];
    }
}
