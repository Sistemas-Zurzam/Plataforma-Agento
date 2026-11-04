<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DescartarPostulacionRequest extends FormRequest
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
            'causa' => ['required', Rule::in(FlujoPostulacion::CAUSAS_DESCARTE)],
            'motivo' => ['required', 'string', 'min:5', 'max:2000'],
            'mensaje_publico' => ['nullable', 'string', 'max:300'],
        ];
    }
}
