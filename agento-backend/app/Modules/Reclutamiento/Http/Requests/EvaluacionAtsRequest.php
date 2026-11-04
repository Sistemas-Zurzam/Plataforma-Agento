<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluacionAtsRequest extends FormRequest
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
            'requisitos' => ['array'],
            'requisitos.*' => [Rule::in(['cumple', 'no_cumple', 'sin_informacion'])],
            'competencias' => ['array'],
            'competencias.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
