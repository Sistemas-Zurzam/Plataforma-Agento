<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecidirSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(['aceptar', 'devolver', 'rechazar'])],
            'motivo' => ['nullable', 'required_if:accion,devolver,rechazar', 'string', 'max:2000'],
            'reclutador_user_id' => ['nullable', 'integer'],
        ];
    }
}
