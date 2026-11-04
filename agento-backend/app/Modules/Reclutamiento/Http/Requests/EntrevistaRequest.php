<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\Entrevista;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntrevistaRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(Entrevista::TIPOS)],
            'programada_at' => ['nullable', 'date'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'evaluador_user_id' => ['nullable', 'integer'],
        ];
    }
}
