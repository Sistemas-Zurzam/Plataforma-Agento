<?php

namespace App\Modules\Reclutamiento\Http\Resources\Publico;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Publicación para el portal público. Lista blanca de campos: sin motivos
 * internos, pesos, umbral, reclutador, área ni ids internos. En procesos
 * confidenciales no se revela la empresa.
 */
class PublicacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $confidencial = $this->empresa_confidencial;
        $perfil = $this->relationLoaded('perfilVigente') ? $this->perfilVigente : null;

        return [
            'codigo' => $this->codigo,
            'cargo' => $this->cargo,
            'descripcion' => $this->descripcion,
            'modalidad' => $this->modalidad,
            'tipo_contrato' => $this->tipo_contrato,
            'ubicacion' => $this->ubicacion,
            'salario' => $this->mostrar_salario ? ['min' => $this->salario_min, 'max' => $this->salario_max, 'moneda' => $this->moneda] : null,
            'empresa' => $confidencial ? null : [
                'nombre' => $this->empresa?->nombre_comercial,
                'logo_url' => $this->empresa?->logo_path ? Storage::disk('public')->url($this->empresa->logo_path) : null,
            ],
            'confidencial' => $confidencial,
            'estado' => $this->estado,
            'acepta_postulaciones' => $this->aceptaPostulaciones(),
            'mensaje_publico' => $this->aceptaPostulaciones() ? null : $this->mensaje_publico,
            'fecha_cierre_postulacion' => $this->fecha_cierre_postulacion?->toDateString(),
            'publicada_at' => $this->publicada_externa_at?->toIso8601String() ?? $this->publicada_interna_at?->toIso8601String(),
            'requisitos' => $perfil ? $perfil->requisitos->map->only(['id', 'tipo', 'descripcion'])->values() : [],
            'competencias' => $perfil ? $perfil->competencias->pluck('nombre')->values() : [],
        ];
    }
}
