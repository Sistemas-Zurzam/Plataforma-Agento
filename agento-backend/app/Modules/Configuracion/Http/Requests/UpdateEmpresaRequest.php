<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'fecha_inscripcion_remype' => ['nullable', 'date', 'required_if:inscrita_remype,true'],
            'numero_registro_remype' => ['nullable', 'string', 'max:255', 'required_if:inscrita_remype,true'],
            'seguro_salud' => ['nullable', Rule::in(['essalud', 'sis'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('seguro_salud') !== 'sis') {
                return;
            }

            $esMicroRemype = $this->input('regimen_laboral') === 'Micro Empresa' && $this->boolean('inscrita_remype');

            if (! $esMicroRemype) {
                $validator->errors()->add(
                    'seguro_salud',
                    'Solo una Micro Empresa inscrita en REMYPE puede optar por SIS en lugar de EsSalud.',
                );
            }
        });
    }
}
