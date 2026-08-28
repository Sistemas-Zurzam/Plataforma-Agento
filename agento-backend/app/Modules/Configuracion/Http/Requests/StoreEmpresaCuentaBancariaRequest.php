<?php

namespace App\Modules\Configuracion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmpresaCuentaBancariaRequest extends FormRequest
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
            'banco_id' => ['required', 'integer', 'exists:bancos,id'],
            // Solo lo que Empresa necesita como cuenta de CARGO (Sección 10
            // del encargo Telecrédito) — "ahorro" no aplica a una cuenta de
            // cargo empresarial, por eso el dominio es distinto al de
            // Colaborador (ahorro/corriente).
            'tipo_cuenta' => ['required', Rule::in(['corriente', 'maestra'])],
            'moneda' => ['required', Rule::in(['PEN', 'USD'])],
            // Solo números, sin guiones (Sección 12) — la máscara/formato es
            // responsabilidad del frontend, nunca de la representación
            // canónica guardada.
            'numero_cuenta' => ['required', 'string', 'regex:/^\d+$/', 'max:20'],
            // Controlado, no texto libre (Sección 13): hoy solo "haberes"
            // tiene sentido de negocio; una cuenta empresarial BCP no debe
            // ofrecerse para nómina solo por existir.
            'uso' => ['required', Rule::in(['haberes'])],
            'es_predeterminada' => ['nullable', 'boolean'],
        ];
    }
}
