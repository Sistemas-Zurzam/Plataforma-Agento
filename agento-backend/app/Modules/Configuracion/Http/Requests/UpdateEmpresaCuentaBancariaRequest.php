<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `banco_id` sigue sin poder editarse — sigue siendo identidad de la
 * cuenta. `numero_cuenta` SÍ se puede corregir (a pedido explícito):
 * `sometimes` porque el Resource nunca devuelve el número crudo
 * (siempre enmascarado), así que el frontend no puede "reenviarlo sin
 * cambios" — si el campo viene ausente/vacío, se deja la cuenta tal cual;
 * solo se actualiza si el usuario escribe un número nuevo completo.
 *
 * Aviso operativo (no es un problema de datos): no existe ningún
 * snapshot histórico de `numero_cuenta` en Boletas/Telecrédito — si se
 * regenera el archivo Telecrédito de un ciclo YA pagado después de editar
 * este número, el archivo regenerado mostrará el número actual, no el que
 * realmente se usó en el pago real. El frontend debe advertirlo.
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
            'numero_cuenta' => [
                'sometimes', 'string', 'regex:/^\d+$/', 'max:20',
                Rule::unique('empresa_cuentas_bancarias', 'numero_cuenta')
                    ->where('empresa_id', $this->route('empresa')?->id)
                    ->ignore($this->route('cuenta')?->id),
            ],
        ];
    }
}
