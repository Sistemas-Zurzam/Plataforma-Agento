<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EscanearCarnetRequest extends FormRequest
{
    /**
     * Solo recorta espacios/salto de línea que la pistola (keyboard wedge)
     * pueda arrastrar alrededor del código — nunca completa, corrige ni
     * elimina caracteres internos.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('codigo'))) {
            $this->merge(['codigo' => trim($this->input('codigo'))]);
        }
    }

    public function rules(): array
    {
        return [
            // El código de barras del carnet ES el número de documento del
            // colaborador (`numero_documento`) — sin formato fijo (DNI,
            // carné de extranjería, pasaporte), así que solo se acota el
            // tamaño; la resolución real ocurre por consulta en
            // RegistrarMarcacionCarnetService::registrar(), que responde
            // "Carnet no reconocido" igual si no coincide con nadie.
            'codigo' => ['required', 'string', 'max:20'],
        ];
    }
}
