<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

class PortalEquipoMensualRequest extends FormRequest
{
    use ValidaRangoDeFechas;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->reglasPaginacion(), [
            'mes' => ['required', 'date_format:Y-m'],
            'busqueda' => ['nullable', 'string', 'max:100'],
            'area_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! AlcancePortal::desdeRequest($this)->puedeVerArea((int) $value)) {
                    $fail('El área indicada no es válida.');
                }
            }],
        ]);
    }
}
