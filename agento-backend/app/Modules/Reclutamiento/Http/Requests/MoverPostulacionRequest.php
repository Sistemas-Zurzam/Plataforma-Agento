<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoverPostulacionRequest extends FormRequest
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
            'etapa' => ['required', Rule::in(FlujoPostulacion::ETAPAS)],
            'motivo' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
