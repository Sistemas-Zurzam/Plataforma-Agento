<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanificarDiaRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
            'fecha' => ['required', 'date'],
            // null/omitido = quitar la planificación de ese día (vuelve a
            // "sin planificar"). 'feriado' no es seleccionable a mano acá:
            // lo asigna únicamente FeriadosPeru.
            'tipo' => ['nullable', Rule::in(['laborable_presencial', 'home_office', 'descanso'])],
        ];
    }
}
