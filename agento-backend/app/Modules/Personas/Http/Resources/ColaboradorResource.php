<?php

namespace App\Modules\Personas\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Personas\Models\Colaborador
 */
class ColaboradorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $remuneracionVigente = $this->remuneraciones->first();

        return [
            'id' => $this->id,
            'legajo' => $this->legajo,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'nombre_completo' => "{$this->nombres} {$this->apellidos}",
            'tipo_documento' => $this->tipo_documento,
            'numero_documento' => $this->numero_documento,
            'email' => $this->email,
            'celular_colaborador' => $this->celular_colaborador,
            'sede' => $this->whenLoaded('sede', fn () => ['id' => $this->sede->id, 'nombre' => $this->sede->nombre]),
            'area' => $this->whenLoaded('area', fn () => ['id' => $this->area->id, 'nombre' => $this->area->nombre]),
            'horario' => $this->whenLoaded('horario', fn () => ['id' => $this->horario->id, 'nombre' => $this->horario->nombre]),
            'cargo' => $this->cargo,
            'tipo_contrato' => $this->tipo_contrato,
            'tipo_trabajador' => $this->tipo_trabajador,
            'fecha_ingreso' => $this->fecha_ingreso?->toDateString(),
            'fecha_fin_contrato' => $this->fecha_fin_contrato?->toDateString(),
            'modalidad_trabajo' => $this->modalidad_trabajo,
            'activo' => $this->activo,
            'remuneracion' => $remuneracionVigente ? [
                'salario' => $remuneracionVigente->salario,
                'moneda_salario' => $remuneracionVigente->moneda_salario,
                'periodicidad_pago' => $remuneracionVigente->periodicidad_pago,
                'asignacion_familiar' => $remuneracionVigente->asignacion_familiar,
            ] : null,
        ];
    }
}
