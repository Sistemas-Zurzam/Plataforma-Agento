<?php

namespace App\Modules\Nominas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarcarIgnoradoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:255'],
        ];
    }
}
