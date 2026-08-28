<?php

namespace App\Modules\Asistencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAsistenciaPermisoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer'],
            // "vacaciones" registra días de vacaciones REALMENTE tomados
            // (hecho de asistencia) — distinto de la provisión contable de
            // vacaciones que ya existe en Nóminas (afecta_vacaciones en
            // conceptos_remuneracion), que es solo la reserva de dinero.
            'tipo' => ['required', Rule::in(['personal', 'medico', 'capacitacion', 'comision_servicio', 'vacaciones', 'otro'])],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'motivo' => ['required', 'string', 'max:1000'],
            // Solo se pide cuando el tipo realmente varía caso a caso
            // (tipos_ausencia.remunerada = NULL para "personal"/
            // "capacitacion") — para el resto, el valor es fijo y se
            // deriva automáticamente en el Service, nunca se le pregunta a
            // RR.HH. algo que el catálogo ya sabe.
            'con_goce' => [Rule::requiredIf(in_array($this->input('tipo'), ['personal', 'capacitacion'], true)), 'boolean'],
            // Solo tiene sentido funcional para descanso médico (ver
            // migración pagador_subsidio) — para cualquier otro tipo debe
            // quedar vacío, nunca un valor arbitrario. Opcional incluso
            // para "medico": RR.HH. lo deja vacío cuando no lo sabe;
            // AfpNet\ResolverExcepcionAfpNet no resuelve la excepción "U"
            // sin este dato confirmado.
            'pagador_subsidio' => [$this->input('tipo') === 'medico' ? 'nullable' : 'prohibited', Rule::in(['empleador', 'essalud_directo'])],
        ];
    }
}
