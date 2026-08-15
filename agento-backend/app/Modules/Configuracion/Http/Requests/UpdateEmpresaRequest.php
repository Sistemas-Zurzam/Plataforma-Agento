<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpresaRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:255'],
            'abreviatura' => ['nullable', 'string', 'max:10'],
            'grupo' => ['nullable', 'string', 'max:255'],
            'ruc' => [
                'nullable',
                'digits:11',
                Rule::unique('empresas', 'ruc')->ignore($this->route('empresa')),
            ],
            'direccion' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'regimen_laboral' => [
                'nullable',
                Rule::in(['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios']),
            ],
            'inscrita_remype' => ['nullable', 'boolean'],
        ];
    }
}
