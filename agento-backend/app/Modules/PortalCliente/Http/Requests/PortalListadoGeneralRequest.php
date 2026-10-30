<?php

namespace App\Modules\PortalCliente\Http\Requests;

use App\Modules\Configuracion\Models\Sede;
use App\Modules\PortalCliente\Application\AlcancePortal;
use App\Modules\PortalCliente\Http\Requests\Concerns\ValidaRangoDeFechas;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Usado por los listados generales de incidencias/horas-extra/permisos de
 * TODO el alcance (empresa o áreas autorizadas). area_id/colaborador_id son
 * opcionales pero, si se envían, deben pertenecer a ese alcance — nunca se
 * confía en el id por sí solo.
 */
class PortalListadoGeneralRequest extends FormRequest
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
            [
                'estado' => ['nullable', 'string', 'max:30'],
                // area_id/sede_id (no nombres): un identificador de otra
                // empresa falla la validación con 422 en vez de devolver
                // cero filas en silencio o revelar si ese id existe en otra
                // empresa — mismo mensaje genérico para ambos casos.
                'area_id' => [
                    'nullable', 'integer',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! AlcancePortal::desdeRequest($this)->puedeVerArea((int) $value)) {
                            $fail('El área indicada no es válida.');
                        }
                    },
                ],
                'sede_id' => [
                    'nullable', 'integer',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        $empresaActivaId = $this->user('api')->empresa_id;
                        if (! Sede::where('id', $value)->where('empresa_id', $empresaActivaId)->exists()) {
                            $fail('La sede indicada no es válida.');
                        }
                    },
                ],
                'busqueda' => ['nullable', 'string', 'max:255'],
                'colaborador_id' => [
                    'nullable', 'integer',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! AlcancePortal::desdeRequest($this)->colaboradores()->whereKey((int) $value)->exists()) {
                            $fail('El colaborador indicado no es válido.');
                        }
                    },
                ],
            ],
        );
    }
}
