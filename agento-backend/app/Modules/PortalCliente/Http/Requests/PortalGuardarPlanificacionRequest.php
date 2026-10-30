<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Application\PlanificarEquipoPortal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lote de cambios: cada uno declara el tipo que el usuario vio
 * (tipo_anterior) para detectar cambios concurrentes. 'feriado' nunca es
 * seleccionable. El origen lo asigna el servidor.
 */
class PortalGuardarPlanificacionRequest extends FormRequest
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
            'cambios' => ['required', 'array', 'min:1', 'max:500'],
            'cambios.*.colaborador_id' => ['required', 'integer'],
            'cambios.*.fecha' => ['required', 'date_format:Y-m-d'],
            'cambios.*.tipo' => ['present', 'nullable', Rule::in(PlanificarEquipoPortal::TIPOS)],
            'cambios.*.tipo_anterior' => ['present', 'nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $vistos = [];
            foreach ((array) $this->input('cambios', []) as $indice => $cambio) {
                $clave = ($cambio['colaborador_id'] ?? '').'|'.($cambio['fecha'] ?? '');
                if (isset($vistos[$clave])) {
                    $validator->errors()->add("cambios.{$indice}", 'Hay cambios repetidos para el mismo colaborador y fecha.');
                }
                $vistos[$clave] = true;
            }
        });
    }
}
