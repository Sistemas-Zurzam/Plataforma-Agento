<?php

namespace App\Modules\PortalCliente\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal. Omite deliberadamente motivo_resolucion y
 * resuelto_por (id de un usuario interno de Agento) — son la decisión
 * privada de RR.HH., no información para el cliente.
 *
 * `descripcion` SÍ se expone: se verificó el origen de todos los sitios que
 * la escriben (ProcesarAsistenciaDiaria::sincronizarIncidencia/sincronizarTrabajoEnDescanso,
 * EvaluarIntegridadDescansoSemanal::describir, AsignarDescansoFlexibleSemanal)
 * y en TODOS los casos es una oración generada por el sistema a partir de
 * una plantilla fija (ej. "No se encontraron marcaciones para una jornada
 * laborable.") — nunca texto libre escrito por RR.HH. Es información
 * operativa necesaria para que el cliente entienda la incidencia, no una
 * observación interna.
 */
class PortalIncidenciaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha' => $this->fecha?->toDateString(),
            'tipo' => $this->tipo,
            'estado' => $this->estado,
            'descripcion' => $this->descripcion,
            'resuelto_at' => $this->resuelto_at?->toDateTimeString(),
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
        ];
    }
}
