<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear y editar comparten reglas. empresa_id NO es un campo aceptado: se
 * toma del usuario autenticado.
 */
class GuardarSolicitudRequest extends FormRequest
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
            'area_id' => ['required', 'integer'],
            'cargo' => ['required', 'string', 'min:3', 'max:150'],
            'motivo' => ['required', Rule::in(SolicitudPersonal::MOTIVOS)],
            'plazas' => ['required', 'integer', 'min:1', 'max:50'],
            'salario_min' => ['required', 'numeric', 'min:0'],
            'salario_max' => ['required', 'numeric', 'gte:salario_min'],
            'moneda' => ['nullable', Rule::in(['PEN', 'USD'])],
            'tipo_contrato' => ['required', Rule::in(SolicitudPersonal::TIPOS_CONTRATO)],
            'modalidad' => ['required', Rule::in(SolicitudPersonal::MODALIDADES)],
            'fecha_requerida' => ['required', 'date', 'after_or_equal:today'],
            'descripcion' => ['required', 'string', 'min:30', 'max:2000'],
            'actividades' => ['nullable', 'string', 'max:4000'],
            'justificacion' => ['nullable', 'string', 'max:2000'],
            'presupuesto' => ['nullable', 'numeric', 'min:0'],
            'urgente' => ['boolean'],
            'habilidades' => ['array', 'max:20'],
            'habilidades.*.nombre' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'habilidades.*.nivel' => ['required', 'integer', 'min:1', 'max:5'],
            'enviar' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->boolean('enviar') && empty($this->input('habilidades'))) {
                $v->errors()->add('habilidades', 'Indica al menos una habilidad para enviar la solicitud.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'salario_min' => 'salario mínimo',
            'salario_max' => 'salario máximo',
            'fecha_requerida' => 'fecha requerida',
            'habilidades.*.nombre' => 'habilidad',
            'habilidades.*.nivel' => 'nivel',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        return [...parent::validated(), 'moneda' => $this->input('moneda', 'PEN'), 'urgente' => $this->boolean('urgente')];
    }
}
