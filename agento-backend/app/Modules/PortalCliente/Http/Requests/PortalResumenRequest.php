<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Usado por el resumen general de asistencia y por la pestaña "Resumen" del
 * perfil de un colaborador — ambos son solo agregados sobre un rango, sin
 * paginación.
 */
class PortalResumenRequest extends FormRequest
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
        return $this->reglasRangoFechas((int) config('portal_cliente.rango_maximo_dias.listado'));
    }
}
