<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateComisionAfpRequest extends FormRequest
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
            'vigencia_desde' => ['required', 'date'],
            'aporte_obligatorio_porcentaje' => ['required', 'numeric', 'min:0'],
            'prima_seguro_porcentaje' => ['required', 'numeric', 'min:0'],
            'comision_flujo_porcentaje' => ['required', 'numeric', 'min:0'],
            'comision_mixta_porcentaje' => ['required', 'numeric', 'min:0'],
            'sobre_saldo_anual_porcentaje' => ['required', 'numeric', 'min:0'],
            // Requerido (a diferencia de Store): esto es una corrección de un
            // registro ya existente, no una nueva vigencia legal — debe
            // quedar constancia de por qué se modificó (CLAUDE.md "Corrección
            // vs Nueva vigencia").
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
