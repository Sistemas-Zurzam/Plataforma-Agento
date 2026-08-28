<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClasificarDiaSinRolRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(['descanso', 'laboral'])],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
