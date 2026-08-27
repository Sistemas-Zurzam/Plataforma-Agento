<?php

namespace App\Modules\Personas\Http\Requests;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Afp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreColaboradorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombres' => mb_strtoupper(trim((string) $this->input('nombres')), 'UTF-8'),
            'apellidos' => mb_strtoupper(trim((string) $this->input('apellidos')), 'UTF-8'),
            'numero_documento' => trim((string) $this->input('numero_documento')),
        ]);
    }

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
            'es_trabajador_confianza' => ['nullable', 'boolean'],
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
            'dias_descanso_rotativo_por_semana' => ['nullable', 'integer', 'min:1', 'max:6'],

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
            $tipoContrato = $this->input('tipo_contrato');
            $periodicidad = $this->input('periodicidad_pago');

            if ($tipoContrato !== 'locacion_servicios' && $periodicidad !== 'mensual') {
                $validator->errors()->add(
                    'periodicidad_pago',
                    'La periodicidad debe ser mensual para este tipo de contrato.',
                );
            }

            if ($tipoContrato === 'plazo_fijo' && ! $this->input('fecha_fin_contrato')) {
                $validator->errors()->add(
                    'fecha_fin_contrato',
                    'La fecha de fin es obligatoria para un contrato a plazo fijo.',
                );
            }

            // El sistema nunca adivina el día de descanso de un horario
            // rotativo — si el horario elegido es rotativo, exige saber de
            // antemano cuántos días de descanso a la semana le corresponden
            // a este colaborador (varía por persona, no es un número fijo).
            $horarioId = $this->input('horario_id');
            $horario = $horarioId ? Horario::find($horarioId) : null;
            if ($horario?->tipo_turno === 'rotativo' && ! $this->input('dias_descanso_rotativo_por_semana')) {
                $validator->errors()->add(
                    'dias_descanso_rotativo_por_semana',
                    'Indica cuántos días de descanso a la semana le corresponden — este horario es rotativo.',
                );
            }

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
