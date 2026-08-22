<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResumenAsistenciaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['required', 'date', 'after_or_equal:fecha_desde'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:30'],
            'sede' => ['nullable', 'string', 'max:150'],
            'area' => ['nullable', 'string', 'max:150'],
            'preparacion' => ['nullable', 'in:todos,listos,sin_horario,sin_calendario'],
            'area_id' => ['nullable', 'integer'],
            'colaborador_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
