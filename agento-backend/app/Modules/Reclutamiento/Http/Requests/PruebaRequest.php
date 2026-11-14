<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\Prueba;
use App\Modules\Reclutamiento\Models\PruebaPregunta;
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
            'area_conocimiento' => ['nullable', 'string', 'max:100'],
            'tiempo_limite_minutos' => ['required', 'integer', 'min:1', 'max:240'],
            'dimensiones' => ['required', 'array', 'min:1', 'max:20'],
            'dimensiones.*.clave' => ['required', 'string', 'max:40', 'distinct'],
            'dimensiones.*.nombre' => ['required', 'string', 'max:100'],
            // Peso 0 solo tiene sentido en la escala de validez (no suma).
            'dimensiones.*.peso' => ['required', 'integer', 'min:0', 'max:100'],
            'dimensiones.*.codigo' => ['nullable', 'string', 'max:10', 'distinct:ignore_case'],
            'dimensiones.*.es_validez' => ['boolean'],
            'dimensiones.*.nivel_medio_desde' => ['nullable', 'numeric', 'min:0', 'required_with:dimensiones.*.nivel_alto_desde'],
            'dimensiones.*.nivel_alto_desde' => ['nullable', 'numeric', 'min:0', 'required_with:dimensiones.*.nivel_medio_desde', 'gt:dimensiones.*.nivel_medio_desde'],
            'dimensiones.*.alerta_desde' => ['nullable', 'numeric', 'min:0'],
            'dimensiones.*.interpretacion_alta' => ['nullable', 'string', 'max:1000'],
            'dimensiones.*.interpretacion_baja' => ['nullable', 'string', 'max:1000'],
            'dimensiones.*.pregunta_entrevista' => ['nullable', 'string', 'max:1000'],
            'perfiles_puesto' => ['array', 'max:20'],
            'perfiles_puesto.*.nombre' => ['required', 'string', 'max:120'],
            'perfiles_puesto.*.dimensiones' => ['required', 'array', 'min:1'],
            'perfiles_puesto.*.dimensiones.*' => ['string', 'max:10'],
            'banco_agento' => ['boolean'],
            // Escala de la prueba (ej. 1 a 5): la usan las afirmaciones de tipo "escala".
            'escala' => ['array', 'max:10'],
            'escala.*.valor' => ['required', 'numeric', 'min:0', 'max:100', 'distinct'],
            'escala.*.etiqueta' => ['required', 'string', 'max:120'],
            'preguntas' => ['array', 'max:200'],
            'preguntas.*.tipo' => ['nullable', Rule::in(PruebaPregunta::TIPOS)],
            'preguntas.*.invertida' => ['boolean'],
            'preguntas.*.dimension' => ['required', 'string', 'max:40'],
            'preguntas.*.enunciado' => ['required', 'string', 'max:2000'],
            'preguntas.*.imagen_id' => ['nullable', 'integer'],
            'preguntas.*.peso' => ['nullable', 'integer', 'min:1', 'max:10'],
            'preguntas.*.opciones' => ['exclude_if:preguntas.*.tipo,escala', 'required', 'array', 'min:2', 'max:10'],
            'preguntas.*.opciones.*.texto' => ['exclude_if:preguntas.*.tipo,escala', 'nullable', 'string', 'max:500', 'required_without:preguntas.*.opciones.*.imagen_id'],
            'preguntas.*.opciones.*.imagen_id' => ['exclude_if:preguntas.*.tipo,escala', 'nullable', 'integer'],
            'preguntas.*.opciones.*.valor' => ['exclude_if:preguntas.*.tipo,escala', 'required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
