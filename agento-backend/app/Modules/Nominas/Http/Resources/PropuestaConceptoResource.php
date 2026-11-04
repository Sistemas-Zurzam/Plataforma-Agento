<?php

namespace App\Modules\Nominas\Http\Resources;

use App\Modules\Nominas\Models\PropuestaConcepto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proyección de una propuesta de bono/comisión. Solo el monto PROPUESTO
 * (dato que el propio solicitante ingresó); nunca salario, boleta ni otros
 * conceptos del colaborador. Usada por el portal y por la bandeja de RR. HH.
 */
class PropuestaConceptoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $validada = $this->estado === PropuestaConcepto::ESTADO_VALIDADO;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'monto' => (string) $this->monto,
            'motivo' => $this->motivo,
            'origen' => $this->origen,
            'estado' => $this->estado,
            'siguiente_responsable' => $this->estado === PropuestaConcepto::ESTADO_PROPUESTO ? 'rrhh' : null,
            'observacion_rrhh' => $this->observacion_rrhh,
            // Validado = registrado como concepto del ciclo; el pago depende
            // del cierre y pago del ciclo (no se infiere aquí más allá de eso).
            'incluido_en_ciclo' => $validada && $this->colaborador_concepto_periodo_id !== null,
            'retirado_por_rrhh' => $validada && $this->colaborador_concepto_periodo_id === null,
            'ciclo_pagado' => $validada && $this->ciclo?->estado === 'pagado',
            'ciclo' => $this->whenLoaded('ciclo', fn () => [
                'id' => $this->ciclo->id,
                'nombre' => $this->ciclo->nombre,
                'fecha_inicio' => $this->ciclo->fecha_inicio?->toDateString(),
                'fecha_fin' => $this->ciclo->fecha_fin?->toDateString(),
            ]),
            'colaborador' => $this->whenLoaded('colaborador', fn () => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ]),
            'propuesto_por' => $this->whenLoaded('proponente', fn () => $this->proponente?->name),
            'resuelto_por' => $this->whenLoaded('resolutor', fn () => $this->resolutor?->name),
            'resuelto_at' => $this->resuelto_at?->toDateTimeString(),
            'creado_at' => $this->created_at?->toDateTimeString(),
            'actualizado_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
