<?php

namespace App\Modules\Personas\Support;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El calendario inicial de un colaborador (Paso 2 del alta) solo cubre su
 * mes de ingreso — a partir del segundo mes no existe ninguna fila en
 * colaborador_calendario_dias para él. Esta clase genera el mes faltante
 * bajo demanda: el tipo de cada día se hereda del mes más reciente que sí
 * tenga datos, comparando el mismo día de la semana **y la misma paridad
 * de semana ISO** (par/impar) — así se replica correctamente un patrón
 * quincenal alternado (p. ej. "miércoles de semana par, jueves de semana
 * impar"), no solo un patrón semanal fijo. Los feriados propios del mes
 * objetivo se vuelven a aplicar automáticamente. El resultado se persiste
 * como filas reales — queda editable igual que cualquier otro mes.
 */
class CalendarioMensualGenerator
{
    /**
     * @return Collection<int, ColaboradorCalendarioDia>
     */
    public static function paraMes(Colaborador $colaborador, int $anio, int $mes): Collection
    {
        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->copy()->endOfMonth();

        $existentes = self::consultarMes($colaborador, $inicioMes, $finMes);
        if ($existentes->isNotEmpty()) {
            return $existentes;
        }

        $fechaIngreso = $colaborador->fecha_ingreso->copy()->startOfDay();
        $desde = $fechaIngreso->gt($inicioMes) ? $fechaIngreso : $inicioMes->copy();

        if ($desde->gt($finMes)) {
            return collect();
        }

        $patron = self::patronDesdeMesesAnteriores($colaborador, $inicioMes);

        $filas = [];
        for ($fecha = $desde->copy(); $fecha->lte($finMes); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            $filas[] = [
                'colaborador_id' => $colaborador->id,
                'fecha' => $fechaTexto,
                'tipo' => FeriadosPeru::esFeriado($fechaTexto)
                    ? 'feriado'
                    : ($patron[self::claveSemana($fecha)] ?? 'laborable_presencial'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($filas === []) {
            return collect();
        }

        ColaboradorCalendarioDia::query()->insert($filas);

        return self::consultarMes($colaborador, $inicioMes, $finMes);
    }

    /**
     * @return Collection<int, ColaboradorCalendarioDia>
     */
    private static function consultarMes(Colaborador $colaborador, Carbon $inicioMes, Carbon $finMes): Collection
    {
        return ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()])
            ->orderBy('fecha')
            ->get();
    }

    /**
     * Busca hacia atrás, mes por mes si hace falta, hasta juntar un tipo
     * para cada una de las 14 combinaciones posibles (7 días × paridad de
     * semana par/impar). Se detiene apenas las completa o se queda sin
     * historial (colaborador recién ingresado, sin mes previo).
     *
     * @return array<string, string> clave "paridad-diaISO" => tipo
     */
    private static function patronDesdeMesesAnteriores(Colaborador $colaborador, Carbon $inicioMes): array
    {
        $anteriores = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('fecha', '<', $inicioMes->toDateString())
            ->where('tipo', '!=', 'feriado')
            ->orderByDesc('fecha')
            ->get();

        $patron = [];
        foreach ($anteriores as $dia) {
            $clave = self::claveSemana($dia->fecha);
            $patron[$clave] ??= $dia->tipo;
            if (count($patron) === 14) {
                break;
            }
        }

        return $patron;
    }

    /**
     * "paridad de semana ISO"-"día ISO" — p. ej. "0-3" es un miércoles de
     * semana par, "1-3" un miércoles de semana impar. La semana ISO es
     * continua a lo largo del año, así que la alternancia par/impar se
     * mantiene correctamente de un mes a otro (con el único límite normal
     * de que puede reiniciar en el cambio de año).
     */
    private static function claveSemana(Carbon $fecha): string
    {
        return ($fecha->isoWeek() % 2).'-'.$fecha->dayOfWeekIso;
    }
}
