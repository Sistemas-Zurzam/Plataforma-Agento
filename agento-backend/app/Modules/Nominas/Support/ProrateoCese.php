<?php

namespace App\Modules\Nominas\Support;

use Illuminate\Support\Carbon;

/**
 * Días de un período que NO se pagan porque el colaborador cesó antes de que
 * terminara. La fecha de cese es el último día trabajado (se paga inclusive).
 *
 * Mes de 30 días: se pagan como máximo 30 días desde el inicio del período, y
 * si el cese cae en el último día del período (ej. 28 de febrero o 31 de un
 * mes de 31 días) se paga el período completo. Simétrico a
 * ProrateoIngresoTardio, con el que se suma cuando ingreso y cese caen en el
 * mismo período.
 */
final class ProrateoCese
{
    public static function diasNoPagados(?Carbon $fechaCese, string $fechaInicio, string $fechaFin): float
    {
        if (! $fechaCese) {
            return 0.0;
        }

        $cese = Carbon::parse($fechaCese->toDateString());
        $inicio = Carbon::parse($fechaInicio);
        $fin = Carbon::parse($fechaFin);

        if ($cese->gte($fin)) {
            return 0.0;
        }
        if ($cese->lt($inicio)) {
            return 30.0;
        }

        $diasTrabajados = min(30, (int) round($inicio->diffInDays($cese)) + 1);

        return (float) (30 - $diasTrabajados);
    }
}
