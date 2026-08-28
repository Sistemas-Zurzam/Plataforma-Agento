<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultarPlanificacionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Cualquier fecha dentro de la semana a consultar — el servicio
            // resuelve lunes-domingo a partir de ella.
            'semana' => ['required', 'date'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'solo_rotativos' => ['nullable', 'boolean'],
            'sede_id' => ['nullable', 'integer'],
        ];
    }
}
