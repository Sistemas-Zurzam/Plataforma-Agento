<?php

namespace App\Modules\Personas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarColaboradoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']];
    }
}
