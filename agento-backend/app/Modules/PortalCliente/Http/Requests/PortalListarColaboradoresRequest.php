<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

class PortalListarColaboradoresRequest extends FormRequest
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
            'busqueda' => ['nullable', 'string', 'max:255'],
            'area_id' => ['nullable', 'integer'],
            'sede_id' => ['nullable', 'integer'],
            'estado' => ['nullable', 'string', 'in:activo,inactivo'],
        ]);
    }
}
