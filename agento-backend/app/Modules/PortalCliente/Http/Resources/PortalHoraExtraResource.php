<?php

namespace App\Modules\PortalCliente\Http\Resources;

use App\Modules\Asistencia\Application\DecidirHoraExtraGerencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal. Omite deliberadamente "motivo" (la nota que deja
 * RR. HH. al validar/rechazar, ver AsistenciaDecisionService::resolverHoraExtra)
 * y resuelto_por — son la decisión privada de RR.HH. Nunca incluye importes:
 * la tasa se expresa solo como porcentaje de recargo; el monto lo calcula
 * Nómina y no forma parte de esta proyección.
 */
class PortalHoraExtraResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $validado = $this->estado === AsistenciaHoraExtra::ESTADO_APROBADO;

        return [
            'id' => $this->id,
            'fecha' => $this->fecha?->toDateString(),
            'minutos_observados' => $this->minutos_observados,
            'minutos_solicitados' => $this->minutos_solicitados,
            'minutos_aprobados' => $this->minutos_aprobados,
            'minutos_aprobados_gerencia' => $this->minutos_aprobados_gerencia,
            'minutos_validados_rrhh' => $validado ? $this->minutos_aprobados : null,
            'tasa' => $this->tasa,
            'tasa_etiqueta' => "{$this->tasa}%",
            'estado' => $this->estado,
            'siguiente_responsable' => match ($this->estado) {
                AsistenciaHoraExtra::ESTADO_PENDIENTE => 'gerencia',
                AsistenciaHoraExtra::ESTADO_APROBADO_GERENCIA => 'rrhh',
                default => null,
            },
            'motivo_gerencia' => $this->motivo_gerencia,
            'gerencia_resuelto_por' => $this->whenLoaded('gerenciaResueltoPor', fn () => $this->gerenciaResueltoPor?->name),
            'gerencia_resuelto_at' => $this->gerencia_resuelto_at?->toDateTimeString(),
            'validado_rrhh_at' => $validado ? $this->resuelto_at?->toDateTimeString() : null,
            'actualizado_at' => $this->updated_at?->toDateTimeString(),
            'version' => DecidirHoraExtraGerencia::version($this->resource),
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
        ];
    }
}
