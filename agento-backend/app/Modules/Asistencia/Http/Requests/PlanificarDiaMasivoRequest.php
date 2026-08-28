<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanificarDiaMasivoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'colaborador_ids' => ['required', 'array', 'min:1'],
            'colaborador_ids.*' => ['integer', 'exists:colaboradores,id'],
            'fechas' => ['required', 'array', 'min:1'],
            'fechas.*' => ['date'],
            'tipo' => ['required', Rule::in(['laborable_presencial', 'home_office', 'descanso'])],
        ];
    }
}
