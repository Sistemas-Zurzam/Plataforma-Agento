<?php

namespace App\Modules\Reclutamiento\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcesoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plazas = $this->relationLoaded('plazas') ? $this->plazas : null;

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'solicitud_id' => $this->solicitud_id,
            'area' => $this->whenLoaded('area', fn () => ['id' => $this->area->id, 'nombre' => $this->area->nombre]),
            'area_id' => $this->area_id,
            'reclutador' => $this->whenLoaded('reclutador', fn () => $this->reclutador ? ['id' => $this->reclutador->id, 'nombre' => $this->reclutador->name] : null),
            'cargo' => $this->cargo,
            'descripcion' => $this->descripcion,
            'modalidad' => $this->modalidad,
            'tipo_contrato' => $this->tipo_contrato,
            'ubicacion' => $this->ubicacion,
            'salario_min' => $this->salario_min,
            'salario_max' => $this->salario_max,
            'moneda' => $this->moneda,
            'mostrar_salario' => $this->mostrar_salario,
            'empresa_confidencial' => $this->empresa_confidencial,
            'fecha_requerida' => $this->fecha_requerida?->toDateString(),
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_cierre_postulacion' => $this->fecha_cierre_postulacion?->toDateString(),
            'atrasado' => $this->fecha_requerida?->isPast() && in_array($this->estado, ['abierto', 'en_pausa'], true),
            'estado' => $this->estado,
            'motivo_estado' => $this->motivo_estado,
            'mensaje_publico' => $this->mensaje_publico,
            'publicacion' => [
                'interna' => $this->publicacion_interna,
                'externa' => $this->publicacion_externa,
                'interna_at' => $this->publicada_interna_at?->toIso8601String(),
                'externa_at' => $this->publicada_externa_at?->toIso8601String(),
                'retirada_at' => $this->retirada_at?->toIso8601String(),
            ],
            'plazas_total' => $this->plazas_total ?? $plazas?->where('estado', '!=', 'cancelada')->count(),
            'plazas_cubiertas' => $this->plazas_cubiertas ?? $plazas?->where('estado', 'cubierta')->count(),
            'postulaciones_activas' => $this->whenHas('postulaciones_activas'),
            'perfil' => $this->whenLoaded('perfilVigente', fn () => $this->perfilVigente ? [
                'version' => $this->perfilVigente->version,
                'umbral_ats' => $this->perfilVigente->umbral_ats,
                'pesos' => $this->perfilVigente->pesos(),
                'requisitos' => $this->perfilVigente->requisitos->map->only(['id', 'tipo', 'descripcion'])->values(),
                'competencias' => $this->perfilVigente->competencias->map->only(['id', 'nombre', 'nivel', 'peso'])->values(),
            ] : null),
            'plazas' => $this->whenLoaded('plazas', fn () => PlazaResource::collection($this->plazas)),
            'created_at' => $this->created_at?->toIso8601String(),
            'cubierto_at' => $this->cubierto_at?->toIso8601String(),
        ];
    }
}
