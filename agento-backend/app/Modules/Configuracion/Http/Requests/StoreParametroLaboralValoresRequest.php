<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreParametroLaboralValoresRequest extends FormRequest
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
            'regimen_laboral' => [
                'required',
                Rule::in(['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios']),
            ],
            'vigencia_desde' => ['required', 'date'],
            'valores' => ['required', 'array', 'min:1'],
            'valores.*' => ['numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $idsValidos = ParametroLaboralDefinicion::pluck('id')->all();

            foreach (array_keys($this->input('valores', [])) as $definicionId) {
                if (! in_array((int) $definicionId, $idsValidos, true)) {
                    $validator->errors()->add('valores', "El parámetro {$definicionId} no existe.");
                }
            }
        });
    }
}
