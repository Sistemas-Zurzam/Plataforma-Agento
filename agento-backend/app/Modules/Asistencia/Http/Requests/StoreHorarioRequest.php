<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHorarioRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:255'],
            'tolerancia_minutos' => ['nullable', 'integer', 'min:0'],
            'tipo_turno' => ['nullable', Rule::in(['normal', 'nocturno', 'rotativo'])],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'cruza_medianoche' => ['nullable', 'boolean'],
            'vigencia_desde' => ['required', 'date'],
            'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'],

            'dias' => ['required', 'array', 'size:7'],
            'dias.*.dia_semana' => ['required', 'integer', 'between:0,6'],
            'dias.*.estado' => ['required', Rule::in(['pendiente', 'laborable', 'descanso'])],
            'dias.*.hora_entrada' => ['nullable', 'date_format:H:i'],
            'dias.*.hora_salida' => ['nullable', 'date_format:H:i'],
            'dias.*.refrigerio_inicio' => ['nullable', 'date_format:H:i'],
            'dias.*.refrigerio_fin' => ['nullable', 'date_format:H:i'],
            'dias.*.jornada_nocturna' => ['nullable', 'boolean'],
            'dias.*.permitir_horas_extra' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $diasSemana = collect($this->input('dias', []))->pluck('dia_semana');

            if ($diasSemana->unique()->sort()->values()->all() !== [0, 1, 2, 3, 4, 5, 6]) {
                $validator->errors()->add('dias', 'Debes enviar exactamente los 7 días de la semana (0 a 6), sin repetir.');
            }
        });
    }
}
