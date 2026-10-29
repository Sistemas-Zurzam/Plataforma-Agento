<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NoAplicaRequest extends FormRequest
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
            'componente' => ['required', Rule::in(['ats', 'psicometria', 'entrevistas'])],
            'no_aplica' => ['required', 'boolean'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }
}
