<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditarAsistenciaDiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'entrada' => ['nullable', 'date_format:H:i'],
            'salida' => ['nullable', 'date_format:H:i'],
            'estado' => ['nullable', Rule::in(['presente', 'falta_justificada', 'permiso', 'home_office', 'descanso', 'feriado'])],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
