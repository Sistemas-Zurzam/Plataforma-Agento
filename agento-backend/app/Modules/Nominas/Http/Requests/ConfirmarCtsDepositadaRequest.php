<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmarCtsDepositadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'referencia_deposito' => ['required', 'string', 'max:120'],
            'fecha_deposito' => ['required', 'date'],
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
