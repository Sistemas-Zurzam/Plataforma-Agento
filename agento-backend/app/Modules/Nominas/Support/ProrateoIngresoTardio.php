<?php

namespace App\Modules\Nominas\Support;

use Illuminate\Support\Carbon;

/**
 * Cuántos días de un período NO se pagan porque el colaborador ingresó
 * después de que arrancó ese período — nunca porque falte, sino porque
 * todavía no existía como colaborador esos días (CalendarioMensualGenerator/
 * ProcesarAsistenciaDiaria ni siquiera generan fila para fechas anteriores a
 * fecha_ingreso, así que esos días no pueden llegar a contarse como "falta").
 *
 * Deliberadamente NO recalcula el largo real del período (ej. min(30, fechaFin
 * - fechaInicio)): los ciclos remunerativos no están garantizados a durar
 * exactamente 30 días (CicloRemunerativoService::crear() acepta cualquier
 * rango), así que asumir eso penalizaría a un colaborador ya empleado si
 * alguna vez se crea un ciclo más corto que un mes. Solo resta los días
 * previos al ingreso — si el colaborador ya estaba empleado antes de
 * $fechaInicio, el resultado es 0 y no cambia nada.
 *
 * Es matemáticamente un no-op para LiquidacionCeseService: esa clase ya
 * recorta $fechaInicio a max(inicioDelMes, fecha_ingreso) antes de llamar al
 * motor de planilla, así que fecha_ingreso nunca queda estrictamente después
 * del $fechaInicio que le pasa — el `gt()` de abajo nunca es true ahí.
 */
final class ProrateoIngresoTardio
{
    public static function diasNoPagados(Carbon $fechaIngreso, string $fechaInicio): float
    {
        $inicio = Carbon::parse($fechaInicio);

        return $fechaIngreso->gt($inicio) ? (float) $inicio->diffInDays($fechaIngreso) : 0.0;
    }
}
