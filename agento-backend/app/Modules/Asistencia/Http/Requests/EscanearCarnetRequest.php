<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EscanearCarnetRequest extends FormRequest
{
    /**
     * Solo recorta espacios/salto de línea que la pistola (keyboard wedge)
     * pueda arrastrar alrededor del código — nunca completa, corrige ni
     * elimina caracteres internos. El código en sí (20 dígitos exactos) se
     * valida en rules(); esto no reemplaza esa validación.
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
            // Formato vigente: exactamente 20 dígitos numéricos (ver
            // CarnetCredentialService::generarToken()). Un token hexadecimal
            // anterior, un DNI de 8 dígitos, o cualquier otra longitud deben
            // rechazarse acá, antes de intentar resolver la credencial —
            // nunca se expone en logs (ver el Handler de excepciones / no se
            // registra este campo).
            'codigo' => ['required', 'string', 'regex:/^[0-9]{20}$/'],
        ];
    }
}
