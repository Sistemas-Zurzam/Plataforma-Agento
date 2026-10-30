<?php

namespace App\Modules\PortalCliente\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal. El motivo de un permiso médico no se expone (dato
 * de salud). No incluye efectos remunerativos ni datos de subsidio: el
 * portal no calcula ni muestra impacto en planilla.
 */
class PortalPermisoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $esMedico = $this->tipo === 'medico';

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_fin' => $this->fecha_fin?->toDateString(),
            'estado' => $this->estado,
            'origen' => $this->origen,
            'siguiente_responsable' => $this->estado === 'pendiente' ? 'rrhh' : null,
            'motivo' => $esMedico ? null : $this->motivo,
            'motivo_oculto_por_privacidad' => $esMedico,
            'con_goce' => (bool) $this->con_goce,
            // Respuesta de RR. HH. al resolver: visible para quien solicitó.
            'respuesta_rrhh' => $this->estado !== 'pendiente' ? $this->observacion_resolucion : null,
            'resuelto_at' => $this->resuelto_at?->toDateTimeString(),
            'resuelto_por' => $this->whenLoaded('resueltoPor', fn () => $this->resueltoPor?->name),
            'creado_at' => $this->created_at?->toDateTimeString(),
            'actualizado_at' => $this->updated_at?->toDateTimeString(),
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
        ];
    }
}
