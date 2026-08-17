<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolverAsistenciaMasivaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'accion' => ['required', Rule::in(['aprobar', 'rechazar'])],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
