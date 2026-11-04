<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\Prueba;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PruebaRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::in(Prueba::TIPOS)],
            'tiempo_limite_minutos' => ['required', 'integer', 'min:1', 'max:240'],
            'dimensiones' => ['required', 'array', 'min:1', 'max:20'],
            'dimensiones.*.clave' => ['required', 'string', 'max:40', 'distinct'],
            'dimensiones.*.nombre' => ['required', 'string', 'max:100'],
            'dimensiones.*.peso' => ['required', 'integer', 'min:1', 'max:100'],
            'preguntas' => ['array', 'max:200'],
            'preguntas.*.dimension' => ['required', 'string', 'max:40'],
            'preguntas.*.enunciado' => ['required', 'string', 'max:2000'],
            'preguntas.*.peso' => ['nullable', 'integer', 'min:1', 'max:10'],
            'preguntas.*.opciones' => ['required', 'array', 'min:2', 'max:10'],
            'preguntas.*.opciones.*.texto' => ['required', 'string', 'max:500'],
            'preguntas.*.opciones.*.valor' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
