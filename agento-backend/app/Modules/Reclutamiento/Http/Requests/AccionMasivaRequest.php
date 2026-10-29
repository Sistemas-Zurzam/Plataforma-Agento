<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccionMasivaRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'accion' => ['required', Rule::in(['mover', 'descartar'])],
            'etapa' => ['required_if:accion,mover', 'nullable', Rule::in(FlujoPostulacion::ETAPAS)],
            'causa' => ['required_if:accion,descartar', 'nullable', Rule::in(FlujoPostulacion::CAUSAS_DESCARTE)],
            'motivo' => ['required_if:accion,descartar', 'nullable', 'string', 'max:2000'],
            'mensaje_publico' => ['nullable', 'string', 'max:300'],
        ];
    }
}
