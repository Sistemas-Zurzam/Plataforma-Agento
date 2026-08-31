<?php

namespace App\Modules\Personas\Support;

use Illuminate\Support\Carbon;

/**
 * Feriados oficiales de Perú, fijados por ley — se calculan a partir del
 * año en vez de mantenerse en una tabla editable (fuera de alcance por
 * ahora; ver plan de "Nuevo Colaborador — Paso 2").
 */
class FeriadosPeru
{
    /**
     * @return array<string> fechas en formato Y-m-d
     */
    public static function paraAnio(int $anio): array
    {
        $pascua = Carbon::createFromTimestamp(easter_date($anio));

        return [
            "{$anio}-01-01", // Año Nuevo
            $pascua->copy()->subDays(3)->toDateString(), // Jueves Santo
            $pascua->copy()->subDays(2)->toDateString(), // Viernes Santo
            "{$anio}-05-01", // Día del Trabajo
            "{$anio}-06-29", // San Pedro y San Pablo
            "{$anio}-07-28", // Fiestas Patrias
            "{$anio}-07-29", // Fiestas Patrias
            "{$anio}-08-06", // Batalla de Junín (feriado nacional)
            "{$anio}-08-30", // Santa Rosa de Lima
            "{$anio}-10-08", // Combate de Angamos
            "{$anio}-11-01", // Todos los Santos
            "{$anio}-12-08", // Inmaculada Concepción
            "{$anio}-12-25", // Navidad
        ];
    }

    public static function esFeriado(string $fecha): bool
    {
        $anio = (int) substr($fecha, 0, 4);

        return in_array($fecha, self::paraAnio($anio), true);
    }
}
