<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Reclutamiento\Application\ContratacionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos mínimos que Personas exige para dar de alta (mismos catálogos que
 * StoreColaboradorRequest). Nombres, documento, correo, celular, cargo, área
 * y empresa se transfieren desde la candidatura y el proceso.
 */
class ContratarRequest extends FormRequest
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
            'sede_id' => ['required', 'integer'],
            'horario_id' => ['nullable', 'integer', 'required_unless:es_trabajador_confianza,true'],
            'dias_descanso_rotativo_por_semana' => ['nullable', 'integer', 'min:1', 'max:6'],
            'es_trabajador_confianza' => ['boolean'],
            'tipo_documento' => ['nullable', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => ['nullable', 'string', 'max:20'],
            'celular_colaborador' => ['nullable', 'string', 'max:20'],
            'celular_referencia' => ['required', 'string', 'max:20'],
            'tipo_contrato' => ['nullable', Rule::in(['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'])],
            'regimen_laboral' => ['nullable', Rule::in(Colaborador::REGIMENES_LABORALES)],
            'tipo_trabajador' => ['required', Rule::in(['trabajador', 'practicante', 'locador'])],
            'categoria_trabajador' => ['nullable', Rule::in(Colaborador::CATEGORIAS_TRABAJADOR)],
            'modalidad_trabajo' => ['nullable', Rule::in(['presencial', 'remoto', 'hibrido'])],
            'fecha_ingreso' => ['required', 'date'],
            'fecha_fin_contrato' => ['nullable', 'date', 'after_or_equal:fecha_ingreso'],
            'fecha_fin_prueba' => ['nullable', 'date', 'after:fecha_ingreso'],
            'salario' => ['required', 'numeric', 'min:0'],
            'moneda_salario' => ['nullable', Rule::in(['PEN', 'USD'])],
            'periodicidad_pago' => ['nullable', Rule::in(['mensual', 'quincenal', 'semanal'])],
            'resolucion_cesado' => ['nullable', Rule::in([ContratacionService::RESOLUCION_VINCULAR])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('tipo_trabajador') === 'trabajador' && blank($this->input('categoria_trabajador'))) {
                $v->errors()->add('categoria_trabajador', 'Indica si es empleado u obrero.');
            }
        });
    }
}
