<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Usado por incidencias/horas-extra/permisos DE UN colaborador puntual
 * (route-bound, ya validado contra la empresa activa en el controller).
 */
class PortalListadoColaboradorRequest extends FormRequest
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
        return array_merge(
            $this->reglasRangoFechas((int) config('portal_cliente.rango_maximo_dias.listado')),
            $this->reglasPaginacion(),
            ['estado' => ['nullable', 'string', 'max:30']],
        );
    }
}
