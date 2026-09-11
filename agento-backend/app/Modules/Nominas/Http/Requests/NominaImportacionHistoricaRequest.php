<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NominaImportacionHistoricaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'fecha_corte' => ['required', 'date'],
        ];
    }
}
