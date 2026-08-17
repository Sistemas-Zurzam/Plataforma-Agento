<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAsistenciaPermisoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(['personal', 'medico', 'capacitacion', 'comision_servicio', 'otro'])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
