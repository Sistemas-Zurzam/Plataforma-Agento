<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolverTrabajoEnDescansoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(['pago', 'sustitutorio', 'corregir_planificacion'])],
            'motivo' => ['required', 'string', 'max:2000'],
            'fecha_sustitutoria' => ['required_if:accion,sustitutorio', 'nullable', 'date'],
            // 'descanso' no es una opción válida acá — corregir planificación
            // significa precisamente que el día NO era descanso.
            'tipo' => ['required_if:accion,corregir_planificacion', 'nullable', Rule::in(['laborable_presencial', 'home_office'])],
        ];
    }
}
