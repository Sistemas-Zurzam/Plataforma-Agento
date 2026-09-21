<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarGratificacionPagadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_pago' => ['required', 'date'],
            'referencia_pago' => ['nullable', 'string', 'max:120'],
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
