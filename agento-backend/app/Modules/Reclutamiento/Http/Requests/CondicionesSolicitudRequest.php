<?php

namespace App\Modules\Reclutamiento\Http\Requests;

use App\Modules\Reclutamiento\Models\SolicitudPersonal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Condiciones comerciales del servicio de reclutamiento acordadas con la
 * empresa cliente. Las registra Agento; ningún monto se calcula ni se asume.
 */
class CondicionesSolicitudRequest extends FormRequest
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
            'cobro_modalidad' => ['nullable', Rule::in(SolicitudPersonal::MODALIDADES_COBRO)],
            'cobro_monto' => ['nullable', 'required_with:cobro_modalidad', 'numeric', 'min:0',
                Rule::when($this->input('cobro_modalidad') === 'porcentaje_salario', ['max:100'])],
            'cobro_moneda' => ['nullable', Rule::requiredIf(fn () => in_array($this->input('cobro_modalidad'), ['monto_por_plaza', 'monto_total'], true)), Rule::in(['PEN', 'USD'])],
            'garantia_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'condiciones_notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cobro_modalidad' => 'modalidad de cobro',
            'cobro_monto' => 'monto',
            'cobro_moneda' => 'moneda',
            'garantia_dias' => 'días de garantía',
            'condiciones_notas' => 'notas',
        ];
    }
}
