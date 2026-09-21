<?php

namespace App\Modules\Personas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarFotosPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivos' => ['required', 'array', 'min:1', 'max:200'],
            'archivos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
