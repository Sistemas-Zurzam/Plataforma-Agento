<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarHorariosRequest extends FormRequest
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
