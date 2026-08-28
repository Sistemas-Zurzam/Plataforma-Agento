<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * V3 Fase 3 — A8/A11: mismas reglas que StoreAsistenciaPermisoRequest, sin
 * colaborador_id (se toma de la incidencia que se está resolviendo, nunca
 * de un valor arbitrario del request) — ver
 * AsistenciaDecisionService::resolverIncidenciaConPermiso().
 */
class ResolverIncidenciaConPermisoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['personal', 'medico', 'capacitacion', 'comision_servicio', 'vacaciones', 'otro'])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'motivo' => ['required', 'string', 'max:1000'],
            'con_goce' => [Rule::requiredIf(in_array($this->input('tipo'), ['personal', 'capacitacion'], true)), 'boolean'],
            'pagador_subsidio' => [$this->input('tipo') === 'medico' ? 'nullable' : 'prohibited', Rule::in(['empleador', 'essalud_directo'])],
        ];
    }
}
