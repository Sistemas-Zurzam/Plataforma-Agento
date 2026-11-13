<?php

namespace App\Modules\Auditoria\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListarEventosRequest extends FormRequest
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
            'usuario_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:request,ui,auth'],
            'modulo' => ['nullable', 'string', 'max:60'],
            'metodo' => ['nullable', 'in:GET,POST,PUT,PATCH,DELETE'],
            'resultado' => ['nullable', 'in:exitoso,error'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
