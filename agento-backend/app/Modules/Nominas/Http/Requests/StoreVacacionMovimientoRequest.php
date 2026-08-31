<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVacacionMovimientoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'tipo' => ['required', Rule::in(['devengo_inicial', 'goce', 'pago', 'ajuste'])],
            // Positivo suma saldo (devengo_inicial, ajuste a favor), negativo
            // lo reduce (goce, pago, ajuste en contra) — el signo lo decide
            // quien registra el movimiento, no el tipo.
            'dias' => ['required', 'numeric', 'not_in:0'],
            'descripcion' => ['required', 'string', 'max:255'],
        ];
    }
}
