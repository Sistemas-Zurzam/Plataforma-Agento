<?php

namespace App\Modules\Personas\Http\Requests;

use App\Modules\Configuracion\Models\Afp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreColaboradorRequest extends FormRequest
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
            'sede_id' => ['required', 'integer', 'exists:sedes,id'],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'horario_id' => ['required', 'integer', 'exists:horarios,id'],

            'nombres' => ['required', 'string', 'max:255'],
            'apellidos' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => ['required', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'pais_residencia' => ['nullable', 'string', 'max:255'],
            'ciudad_residencia' => ['nullable', 'string', 'max:255'],
            'distrito_residencia' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular_colaborador' => ['required', 'string', 'max:20'],
            'celular_referencia' => ['required', 'string', 'max:20'],

            'cargo' => ['required', 'string', 'max:255'],
            'tipo_contrato' => ['required', Rule::in(['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'])],
            'regimen_laboral' => [
                'nullable',
                Rule::in(['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios']),
            ],
            'tipo_trabajador' => ['required', Rule::in(['trabajador', 'practicante', 'locador'])],
            'contabilizar_tardanzas' => ['nullable', 'boolean'],
            'contabilizar_horas_extra' => ['nullable', 'boolean'],
            'fecha_ingreso' => ['required', 'date'],
            'fecha_fin_contrato' => ['nullable', 'date', 'after_or_equal:fecha_ingreso'],

            'cts_cuenta' => ['nullable', 'string', 'max:255'],
            'sistema_previsional' => ['nullable', 'string'],
            'banco' => ['nullable', 'string', 'max:255'],
            'numero_cuenta' => ['nullable', 'string', 'max:255'],
            'tipo_cuenta' => ['nullable', Rule::in(['ahorro', 'corriente'])],
            'moneda_cuenta' => ['nullable', Rule::in(['PEN', 'USD'])],
            'cci' => ['nullable', 'string', 'max:20'],

            'modalidad_trabajo' => ['required', Rule::in(['presencial', 'remoto', 'hibrido'])],
            'tolerancia_particular_minutos' => ['nullable', 'integer', 'min:0'],

            'salario' => ['required', 'numeric', 'min:0'],
            'moneda_salario' => ['required', Rule::in(['PEN', 'USD'])],
            'periodicidad_pago' => ['required', Rule::in(['mensual', 'quincenal', 'semanal'])],
            'asignacion_familiar' => ['nullable', 'numeric', 'min:0'],

            'calendario' => ['required', 'array', 'min:1'],
            'calendario.*.fecha' => ['required', 'date'],
            'calendario.*.tipo' => [
                'required',
                Rule::in(['laborable_presencial', 'home_office', 'descanso', 'feriado']),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sistemaPrevisional = $this->input('sistema_previsional');

            if (! $sistemaPrevisional) {
                return;
            }

            $valoresValidos = ['onp', ...Afp::pluck('clave')->all()];

            if (! in_array($sistemaPrevisional, $valoresValidos, true)) {
                $validator->errors()->add('sistema_previsional', 'El sistema previsional indicado no es válido.');
            }
        });
    }
}
