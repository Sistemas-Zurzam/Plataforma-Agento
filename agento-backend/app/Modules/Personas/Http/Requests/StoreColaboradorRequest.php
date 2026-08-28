<?php

namespace App\Modules\Personas\Http\Requests;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreColaboradorRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombres' => mb_strtoupper(trim((string) $this->input('nombres')), 'UTF-8'),
            'apellido_paterno' => mb_strtoupper(trim((string) $this->input('apellido_paterno')), 'UTF-8'),
            'apellido_materno' => mb_strtoupper(trim((string) $this->input('apellido_materno')), 'UTF-8'),
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
            // V3 P3 — un trabajador de confianza no necesita horario
            // obligatorio; para cualquier otro sigue siendo requerido.
            // required_unless (regla string, evaluada contra los datos
            // reales) y NO Rule::requiredIf(closure) a propósito:
            // ImportarColaboradoresService::evaluarFila() reutiliza este
            // rules() vía Validator::make() sobre una instancia "en frío" de
            // este FormRequest (nunca recibe una request HTTP real) — un
            // closure que lea $this->boolean(...) siempre leería del
            // request vacío, nunca de los datos de la fila del Excel.
            'horario_id' => [
                'required_unless:es_trabajador_confianza,true',
                'nullable', 'integer', 'exists:horarios,id',
            ],

            'nombres' => ['required', 'string', 'max:255'],
            // Apellido paterno/materno por separado — exigido por las
            // estructuras E4/E7 de PLAME (SUNAT). Materno queda opcional
            // porque hay casos reales (extranjeros, un solo apellido legal)
            // donde no aplica.
            'apellido_paterno' => ['required', 'string', 'max:100'],
            'apellido_materno' => ['nullable', 'string', 'max:100'],
            // "ruc" solo aplica a locadores (prestadores de servicios 4ta
            // categoría) — Tabla 3 de SUNAT lo habilita exclusivamente para
            // ese caso (ver withValidator). No se ofrece para trabajador/
            // practicante.
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte', 'ruc'])],
            'numero_documento' => [
                'required', 'string',
                $this->input('tipo_documento') === 'ruc' ? 'digits:11' : 'max:20',
            ],
            'fecha_nacimiento' => ['nullable', 'date'],
            'pais_residencia' => ['nullable', 'string', 'max:255'],
            'domiciliado' => ['nullable', 'boolean'],
            'ciudad_residencia' => ['nullable', 'string', 'max:255'],
            'distrito_residencia' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'celular_colaborador' => ['required', 'string', 'max:20'],
            'celular_referencia' => ['required', 'string', 'max:20'],

            'cargo' => ['required', 'string', 'max:255'],
            'tipo_contrato' => ['required', Rule::in(['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'])],
            'regimen_laboral' => ['nullable', Rule::in(Colaborador::REGIMENES_LABORALES)],
            'tipo_trabajador' => ['required', Rule::in(['trabajador', 'practicante', 'locador'])],
            // Solo aplica cuando tipo_trabajador = trabajador (Empleado vs
            // Obrero, Tabla 8 SUNAT) — validado en withValidator() para
            // exigirlo ahí y prohibirlo en los demás casos.
            'categoria_trabajador' => ['nullable', Rule::in(Colaborador::CATEGORIAS_TRABAJADOR)],
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

            // Sin horario no hay calendario que generar (ver horario_id) —
            // 'required' ya falla para un array vacío, no hace falta 'min:1'
            // aparte.
            //
            // Rotativo Fase 1 — un horario rotativo TAMPOCO tiene calendario
            // inicial que generar: las fechas de descanso se planifican
            // después (Planificación de horarios), nunca se inventa un mes
            // "laborable" ficticio al crear el colaborador. Se detecta vía
            // dias_descanso_rotativo_por_semana (ya es obligatorio solo
            // cuando el horario elegido es rotativo, ver withValidator())
            // en vez de consultar Horario::tipo_turno acá. Este closure SÍ
            // usa $this->input()/$this->boolean() a propósito — a
            // diferencia de horario_id, ImportarColaboradoresService
            // EXCLUYE explícitamente las reglas 'calendario*' antes de
            // reutilizar este Request en bruto (ver evaluarFila()), así que
            // el bug de la Fase 4 (closure leyendo un FormRequest vacío) no
            // puede repetirse acá: este closure nunca corre en ese flujo.
            'calendario' => [
                function ($attribute, $value, $fail) {
                    if ($this->boolean('es_trabajador_confianza') || filled($this->input('dias_descanso_rotativo_por_semana'))) {
                        return;
                    }
                    if (! is_array($value) || count($value) === 0) {
                        $fail('El calendario inicial es obligatorio.');
                    }
                },
                'array',
            ],
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
            if ($this->input('tipo_documento') === 'ruc' && $this->input('tipo_trabajador') !== 'locador') {
                $validator->errors()->add(
                    'tipo_documento',
                    'El tipo de documento RUC solo aplica a locadores (Tabla 3 de SUNAT lo habilita exclusivamente para prestadores de servicios).',
                );
            }

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

            $tipoTrabajador = $this->input('tipo_trabajador');
            $categoriaTrabajador = $this->input('categoria_trabajador');

            if ($tipoTrabajador === 'trabajador' && ! $categoriaTrabajador) {
                $validator->errors()->add(
                    'categoria_trabajador',
                    'Indica si es Empleado u Obrero — requerido por SUNAT (Tabla 8) para el tipo de trabajador "trabajador".',
                );
            }

            if ($tipoTrabajador !== 'trabajador' && $categoriaTrabajador) {
                $validator->errors()->add(
                    'categoria_trabajador',
                    'La categoría laboral (Empleado/Obrero) solo aplica cuando el tipo de trabajador es "trabajador".',
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
