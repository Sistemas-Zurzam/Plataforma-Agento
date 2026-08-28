<?php

namespace App\Modules\Configuracion\Http\Requests;

use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreParametroLaboralValoresRequest extends FormRequest
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
            'regimen_laboral' => [
                'required',
                Rule::in(['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios']),
            ],
            'vigencia_desde' => ['required', 'date'],
            'valores' => ['required', 'array', 'min:1'],
            'valores.*' => ['numeric', 'min:0'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $idsValidos = ParametroLaboralDefinicion::pluck('id')->all();

            foreach (array_keys($this->input('valores', [])) as $definicionId) {
                if (! in_array((int) $definicionId, $idsValidos, true)) {
                    $validator->errors()->add('valores', "El parámetro {$definicionId} no existe.");
                }
            }

            $this->validarTasaSobretasaLegal($validator);
        });
    }

    /**
     * Fase 3.2.1 — la sobretasa del 100% por trabajo en día de descanso o
     * feriado (D.Leg. 713, Art. 3°/4°) no es una tasa de negocio negociable
     * como las de horas extra ordinaria (25%/35%): es un mínimo legal fijo.
     * `horas_extra_tasa_nocturna` hoy alimenta ese cálculo
     * (PlanillaDependienteCalculator::calcularHorasExtra(), tramo HE_100) sin
     * ningún candado — sin esta validación, se podía configurar por error en
     * 1.25/1.35 y subpagar esa sobretasa en silencio, empresa por empresa.
     * "Locación de Servicios" queda exenta porque no es una relación
     * laboral: el D.Leg. 713 no le aplica.
     */
    private function validarTasaSobretasaLegal(Validator $validator): void
    {
        if ($this->input('regimen_laboral') === 'Locacion de Servicios') {
            return;
        }

        $definicionId = ParametroLaboralDefinicion::where('clave', 'horas_extra_tasa_nocturna')->value('id');
        $valor = $definicionId ? $this->input("valores.{$definicionId}") : null;

        if ($valor !== null && (float) $valor !== 2.0) {
            $validator->errors()->add(
                "valores.{$definicionId}",
                'La tasa de trabajo en descanso/feriado corresponde a una sobretasa legal fija del 100% (D.Leg. 713, Art. 3°/4°) — el valor debe ser 2.00, no es una tasa negociable como las de horas extra ordinaria.',
            );
        }
    }
}
