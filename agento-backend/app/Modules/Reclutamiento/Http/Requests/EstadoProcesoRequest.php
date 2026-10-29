<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstadoProcesoRequest extends FormRequest
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
            'accion' => ['required', Rule::in(['pausar', 'reanudar', 'cerrar'])],
            'motivo' => ['nullable', 'required_if:accion,pausar,cerrar', 'string', 'max:2000'],
            'mensaje_publico' => ['nullable', 'string', 'max:300'],
        ];
    }
}
