<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransicionarAsistenciaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(['aprobar', 'rechazar', 'observar', 'cerrar', 'reabrir', 'enviar_nomina'])],
            'motivo' => ['required', 'string', 'max:2000'],
            'minutos_aprobados' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
