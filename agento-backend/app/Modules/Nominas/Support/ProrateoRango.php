<?php

namespace App\Modules\Nominas\Support;

use Illuminate\Support\Carbon;

/**
 * Proyectado de planilla por rango de fechas (ej. una quincena): días del
 * mes de 30 que quedan fuera del rango y no se pagan. Un rango que cubre un
 * mes calendario completo vale 30 días (igual que la planilla mensual, aun
 * en febrero o en meses de 31). Si el colaborador cesa dentro del rango, el
 * recorte ya lo hace ProrateoCese (cuenta desde el inicio del rango) y aquí
 * no se descuenta nada, para no duplicarlo. Se suma con
 * ProrateoIngresoTardio, que también cuenta desde el inicio del rango.
 */
final class ProrateoRango
{
    public static function diasDelRango(string $fechaDesde, string $fechaHasta): int
    {
        $desde = Carbon::parse($fechaDesde);
        $hasta = Carbon::parse($fechaHasta);

        if ($desde->day === 1 && $hasta->isSameDay($desde->copy()->endOfMonth())) {
            return 30;
        }

        return (int) min(30, round($desde->diffInDays($hasta)) + 1);
    }

    public static function diasNoPagados(string $fechaDesde, string $fechaHasta, ?Carbon $fechaCese = null): float
    {
        if ($fechaCese && $fechaCese->lt(Carbon::parse($fechaHasta))) {
            return 0.0;
        }

        return (float) (30 - self::diasDelRango($fechaDesde, $fechaHasta));
    }
}
