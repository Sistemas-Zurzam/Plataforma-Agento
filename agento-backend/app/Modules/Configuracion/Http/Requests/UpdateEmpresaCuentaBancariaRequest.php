<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A diferencia de Store, NO permite editar `banco_id` ni `numero_cuenta`
 * — son identidad de la cuenta, no datos de configuración. Si el número
 * fue mal ingresado, se desactiva esa cuenta y se agrega una nueva (mismo
 * criterio que la mayoría de sistemas bancarios: no se "corrige" un
 * número de cuenta existente). Esto además evita el problema de mostrar
 * el número completo en el formulario de edición solo para poder
 * reenviarlo sin cambios (Resources lo devuelven enmascarado).
 */
class UpdateEmpresaCuentaBancariaRequest extends FormRequest
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
            'tipo_cuenta' => ['required', Rule::in(['corriente', 'maestra'])],
            'moneda' => ['required', Rule::in(['PEN', 'USD'])],
            'uso' => ['required', Rule::in(['haberes'])],
            'es_predeterminada' => ['nullable', 'boolean'],
        ];
    }
}
