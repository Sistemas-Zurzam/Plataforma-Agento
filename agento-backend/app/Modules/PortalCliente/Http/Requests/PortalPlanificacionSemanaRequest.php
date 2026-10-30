<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Application\AlcancePortal;
use Illuminate\Foundation\Http\FormRequest;

class PortalPlanificacionSemanaRequest extends FormRequest
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
            'semana' => ['required', 'date_format:Y-m-d'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'solo_rotativos' => ['nullable', 'boolean'],
            'area_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! AlcancePortal::desdeRequest($this)->puedeVerArea((int) $value)) {
                    $fail('El área indicada no es válida.');
                }
            }],
        ];
    }
}
