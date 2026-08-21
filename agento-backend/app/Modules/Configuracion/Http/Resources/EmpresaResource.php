<?php

namespace App\Modules\Configuracion\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmpresaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'abreviatura' => $this->abreviatura,
            'grupo' => $this->grupo,
            'ruc' => $this->ruc,
            'direccion' => $this->direccion,
            'color' => $this->color,
            'logo_url' => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
            'regimen_laboral' => $this->regimen_laboral,
            'inscrita_remype' => $this->inscrita_remype,
            'fecha_inscripcion_remype' => $this->fecha_inscripcion_remype?->toDateString(),
            'numero_registro_remype' => $this->numero_registro_remype,
            'seguro_salud' => $this->seguro_salud,
            'activa' => $this->activa,
            // Un administrador global opera como Administrador en TODAS las
            // empresas, incluidas las que no traen `pivot` cargado (porque
            // vinieron de Empresa::all(), no de $user->empresas()) — ver
            // User::esAdministradorGlobal().
            'role' => $this->pivot?->role?->clave
                ?? ($request->user('api')?->esAdministradorGlobal() ? 'administrador' : null),
            'es_activa' => $this->id === $request->user('api')?->empresa_id,
        ];
    }
}
