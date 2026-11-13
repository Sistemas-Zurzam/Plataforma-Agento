<?php

namespace App\Modules\Auditoria\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventosUiRequest extends FormRequest
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
            'eventos' => ['required', 'array', 'min:1', 'max:50'],
            'eventos.*.tipo' => ['required', 'in:click,navegacion'],
            'eventos.*.etiqueta' => ['required', 'string', 'max:150'],
            'eventos.*.ruta' => ['nullable', 'string', 'max:255'],
            'eventos.*.elemento' => ['nullable', 'string', 'max:60'],
            'eventos.*.contexto' => ['nullable', 'string', 'max:150'],
            'eventos.*.ocurrido_en' => ['nullable', 'date'],
        ];
    }
}
