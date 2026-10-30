<?php

namespace App\Modules\Personas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Proyección de una solicitud de cese (portal y bandeja de RR. HH.). Solo
 * datos de la propia solicitud: las evidencias se listan sin ruta (se
 * descargan por un endpoint autorizado), sin datos económicos del
 * colaborador ni de la liquidación.
 */
class SolicitudCeseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $etapa = $this->etapa();

        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'etapa' => $etapa,
            'siguiente_responsable' => in_array($etapa, ['enviada', 'aprobada_pendiente_ejecucion'], true) ? 'rrhh' : null,
            'fecha_cese_propuesta' => $this->fecha_cese_propuesta?->toDateString(),
            'fecha_cese_aprobada' => $this->fecha_cese_aprobada?->toDateString(),
            'motivo_cambio_fecha' => $this->motivo_cambio_fecha,
            'fecha_cese_efectiva' => $this->fecha_cese_efectiva?->toDateString(),
            'motivo' => $this->motivo,
            'motivo_rechazo' => $this->motivo_rechazo,
            'origen' => $this->origen,
            'solicitante' => $this->whenLoaded('solicitante', fn () => $this->solicitante?->name),
            'resuelto_por' => $this->whenLoaded('resolutor', fn () => $this->resolutor?->name),
            'resuelto_at' => $this->resuelto_at?->toDateTimeString(),
            'ejecutada_por' => $this->whenLoaded('ejecutor', fn () => $this->ejecutor?->name),
            'ejecutada_at' => $this->ejecutada_at?->toDateTimeString(),
            'creado_at' => $this->created_at?->toDateTimeString(),
            'actualizado_at' => $this->updated_at?->toDateTimeString(),
            'colaborador' => $this->whenLoaded('colaborador', fn () => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
                'cargo' => $this->colaborador->cargo,
                'activo' => (bool) $this->colaborador->activo,
                'fecha_ingreso' => $this->colaborador->fecha_ingreso?->toDateString(),
            ]),
            'evidencias' => $this->whenLoaded('evidencias', fn () => $this->evidencias->map(fn ($evidencia) => [
                'id' => $evidencia->id,
                'nombre' => $evidencia->nombre_original,
                'mime_type' => $evidencia->mime_type,
                'tamano_bytes' => $evidencia->tamano_bytes,
            ])->values()),
        ];
    }
}
