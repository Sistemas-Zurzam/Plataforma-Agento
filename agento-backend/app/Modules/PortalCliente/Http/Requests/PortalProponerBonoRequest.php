<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\Nominas\Models\PropuestaConcepto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Monto como decimal con máximo 2 decimales (nunca se opera en flotante:
 * se persiste el texto validado en una columna decimal(12,2)).
 */
class PortalProponerBonoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer'],
            'ciclo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(PropuestaConcepto::TIPOS)],
            'monto' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
            'clave_idempotencia' => ['required', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_int($this->input('monto')) || is_float($this->input('monto'))) {
            $this->merge(['monto' => (string) $this->input('monto')]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto.regex' => 'El monto debe ser un número positivo con hasta 2 decimales.',
            'monto.not_regex' => 'El monto debe ser mayor que cero.',
        ];
    }
}
