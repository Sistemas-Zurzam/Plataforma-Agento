<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\EntrevistaEvaluacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluarEntrevistaRequest extends FormRequest
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
            'calificaciones' => ['required', 'array', 'min:1', 'max:20'],
            'calificaciones.*.competencia' => ['required', 'string', 'max:100'],
            'calificaciones.*.calificacion' => ['required', 'integer', 'min:1', 'max:5'],
            'fortalezas' => ['required', 'string', 'max:2000'],
            'riesgos' => ['required', 'string', 'max:2000'],
            'recomendacion' => ['required', Rule::in(EntrevistaEvaluacion::RECOMENDACIONES)],
            'comentarios' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
