<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Acciones que solo reciben un motivo (cancelar, retirar, reconsiderar...).
 * Si el motivo es obligatorio lo decide la regla de dominio de cada acción.
 */
class MotivoRequest extends FormRequest
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
        return ['motivo' => ['nullable', 'string', 'max:2000']];
    }
}
