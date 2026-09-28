<?php

namespace App\Modules\PortalCliente\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal — NO reutiliza AsistenciaColaboradorResource ni
 * ColaboradorResource (admin). Expone como máximo lo que el cliente
 * necesita para asistencia: nunca cuenta bancaria, sueldo, AFP, CUSPP,
 * dirección/teléfono/correo personal ni campos de auditoría interna.
 */
class PortalColaboradorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legajo' => $this->legajo,
            'nombre_completo' => trim("{$this->nombres} {$this->apellidos}"),
            'documento_enmascarado' => $this->documentoEnmascarado(),
            'area' => $this->area?->nombre,
            'sede' => $this->sede?->nombre,
            'cargo' => $this->cargo,
            'estado_laboral' => $this->activo ? 'activo' : 'cesado',
        ];
    }

    private function documentoEnmascarado(): ?string
    {
        if (! $this->numero_documento) {
            return null;
        }

        return trim(sprintf('%s ****%s', $this->tipo_documento, substr($this->numero_documento, -4)));
    }
}
