<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * mimes:xlsx valida el tipo real del contenido (paquete OpenXML), no solo la
 * extensión; el lector vuelve a revisar la estructura interna del archivo.
 * empresa_id nunca se recibe: la empresa sale del ciclo autorizado.
 */
class ImportarConsumoComidaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sistema_origen' => mb_strtoupper(trim((string) ($this->input('sistema_origen') ?: 'PLANTILLA'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:10240'],
            'sistema_origen' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_\- ]+$/'],
            'reemplaza_importacion_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.mimes' => 'El archivo debe ser un Excel .xlsx.',
            'archivo.extensions' => 'El archivo debe tener extensión .xlsx.',
            'archivo.max' => 'El archivo no puede superar 10 MB.',
            'sistema_origen.regex' => 'El sistema de origen solo admite letras, números, espacios, guiones y guiones bajos.',
        ];
    }
}
