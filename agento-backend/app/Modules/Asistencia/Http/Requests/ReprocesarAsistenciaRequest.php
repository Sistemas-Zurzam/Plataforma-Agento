<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprocesarAsistenciaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['required', 'date', 'after_or_equal:fecha_desde', 'before_or_equal:'.now()->addYears(2)->toDateString()],
            'colaborador_ids' => ['nullable', 'array', 'max:500'],
            'colaborador_ids.*' => ['integer'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->date('fecha_desde') || ! $this->date('fecha_hasta')) {
                return;
            }
            if ($this->date('fecha_desde')->diffInDays($this->date('fecha_hasta')) > 62) {
                $validator->errors()->add('fecha_hasta', 'El reproceso no puede abarcar más de 63 días.');
            }
        });
    }
}
