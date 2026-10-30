<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\Asistencia\Application\DecidirHoraExtraGerencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solo la decisión de gerencia: no acepta estado, actor, minutos validados
 * ni montos — el servidor los determina.
 */
class PortalDecidirHoraExtraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', Rule::in(DecidirHoraExtraGerencia::ACCIONES)],
            'minutos' => ['required_if:accion,aprobar_parcial', 'nullable', 'integer', 'min:1', 'max:1440'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'version' => ['required', 'string', 'size:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['minutos.required_if' => 'Indica las horas y minutos a aprobar.'];
    }
}
