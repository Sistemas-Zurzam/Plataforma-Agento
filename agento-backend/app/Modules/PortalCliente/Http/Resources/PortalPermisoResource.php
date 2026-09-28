<?php

namespace App\Modules\PortalCliente\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exclusivo del portal. Se omite deliberadamente observacion_resolucion y
 * resuelto_por — la nota/decisión privada de RR.HH. al aprobar o rechazar,
 * no información para el cliente.
 *
 * `motivo` (StoreAsistenciaPermisoRequest: string libre, hasta 1000
 * caracteres, obligatorio) es lo que quien registra el permiso escribió
 * como razón — para tipos como "personal"/"capacitacion"/"comision_servicio"
 * es información operativa normal para el empleador ("trámite personal",
 * "capacitación en Excel"). Pero para tipo = "medico" no hay ninguna
 * restricción de formato que impida texto con detalle clínico (diagnóstico,
 * tratamiento) — dato sensible de salud que no puede garantizarse seguro.
 * Por eso, para permisos médicos se oculta el texto libre y se expone solo
 * la categoría ya visible en `tipo` — exactamente el fallback que pide la
 * tarea ("categoría segura") cuando no puede garantizarse que el campo no
 * contenga información sensible.
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
            'motivo' => $esMedico ? null : $this->motivo,
            'motivo_oculto_por_privacidad' => $esMedico,
            'con_goce' => (bool) $this->con_goce,
            'colaborador' => [
                'id' => $this->colaborador->id,
                'nombre_completo' => trim("{$this->colaborador->nombres} {$this->colaborador->apellidos}"),
                'legajo' => $this->colaborador->legajo,
                'area' => $this->colaborador->area?->nombre,
            ],
        ];
    }
}
