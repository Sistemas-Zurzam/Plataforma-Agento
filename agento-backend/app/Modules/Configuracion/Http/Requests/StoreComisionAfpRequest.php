<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreComisionAfpRequest extends FormRequest
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
            'afp_id' => ['required', 'integer', 'exists:afps,id'],
            'vigencia_desde' => ['required', 'date'],
            'aporte_obligatorio_porcentaje' => ['required', 'numeric', 'min:0'],
            'prima_seguro_porcentaje' => ['required', 'numeric', 'min:0'],
            'comision_flujo_porcentaje' => ['required', 'numeric', 'min:0'],
            'comision_mixta_porcentaje' => ['required', 'numeric', 'min:0'],
            'sobre_saldo_anual_porcentaje' => ['required', 'numeric', 'min:0'],
        ];
    }
}
