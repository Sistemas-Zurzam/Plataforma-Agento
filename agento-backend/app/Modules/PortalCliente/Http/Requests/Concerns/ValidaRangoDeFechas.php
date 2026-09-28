<?php

namespace App\Modules\PortalCliente\Http\Requests\Concerns;

use Illuminate\Support\Carbon;

trait ValidaRangoDeFechas
{
    /**
     * fecha_desde/fecha_hasta comunes a todos los listados del portal —
     * ninguno acepta un rango ilimitado (ver config('portal_cliente.rango_maximo_dias')).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function reglasRangoFechas(int $maximoDias): array
    {
        return [
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => [
                'required', 'date', 'after_or_equal:fecha_desde',
                function (string $attribute, mixed $value, \Closure $fail) use ($maximoDias): void {
                    // Si fecha_desde/fecha_hasta no son fechas válidas, la
                    // regla `date` de cada campo ya reporta ese error por su
                    // cuenta — acá solo evitamos que Carbon lance una
                    // excepción no controlada sobre un valor no parseable.
                    try {
                        $dias = Carbon::parse($this->input('fecha_desde'))->diffInDays(Carbon::parse($value));
                    } catch (\Throwable) {
                        return;
                    }

                    if ($dias > $maximoDias) {
                        $fail("El rango no puede superar {$maximoDias} días.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function reglasPaginacion(int $maximoPorPagina = 50): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$maximoPorPagina}"],
        ];
    }
}
