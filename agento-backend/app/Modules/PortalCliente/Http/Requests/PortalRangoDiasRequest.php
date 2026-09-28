<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Usado por calendario, marcaciones e historial de un colaborador — datos
 * día a día, por eso el tope de rango es el más estricto (config
 * 'portal_cliente.rango_maximo_dias.calendario'). per_page es opcional y
 * solo lo usa historial (calendario/marcaciones devuelven el rango completo,
 * ya acotado por el tope de días).
 */
class PortalRangoDiasRequest extends FormRequest
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
            $this->reglasRangoFechas((int) config('portal_cliente.rango_maximo_dias.calendario')),
            $this->reglasPaginacion(),
        );
    }
}
