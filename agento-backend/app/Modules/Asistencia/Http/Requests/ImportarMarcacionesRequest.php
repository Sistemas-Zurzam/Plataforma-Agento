<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarMarcacionesRequest extends FormRequest
{
    public function rules(): array
    {
        return ['archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240']];
    }
}
