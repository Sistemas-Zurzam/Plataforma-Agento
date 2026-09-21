<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de forma únicamente (¿es un array?, ¿hay motivo?). La lista
 * blanca real de campos corregibles la aplica
 * `ImportarAntecedentesHistoricosService::corregir()` — este Request nunca
 * decide qué campos son válidos, para no duplicar esa regla en dos lugares.
 */
class CorregirDetalleImportacionHistoricaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cambios' => ['required', 'array', 'min:1'],
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
