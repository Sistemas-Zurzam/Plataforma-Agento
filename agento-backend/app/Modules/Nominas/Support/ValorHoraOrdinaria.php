<?php

namespace App\Modules\Nominas\Support;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;

/**
 * Valor hora para el recargo por sobretiempo: "la remuneración de un día
 * dividida entre el número de horas de la jornada del respectivo trabajador"
 * (D.S. 007-2002-TR, art. 12). Remuneración ordinaria = la mensual fija,
 * incluida la asignación familiar; sin variables ni periódicas (guía MTPE
 * "¿Cómo calcular las horas extras?", criterio administrativo).
 *
 * Jornada: promedio de minutos programados de los días laborables del
 * período según Asistencia. Solo se usa si es menor a 8 h (jornada reducida);
 * con 8 h o más (p. ej. semana de 5 días de 9.6 h) se mantiene 8 h, que es
 * el valor que Agento usaba: reducir el valor hora en esos casos queda
 * pendiente de validación laboral.
 */
final class ValorHoraOrdinaria
{
    public const JORNADA_BASE = 8.0;

    public static function calcular(float $sueldoBasico, float $asignacionFamiliar, ?float $horasJornada): float
    {
        return ($sueldoBasico + $asignacionFamiliar) / 30 / self::jornada($horasJornada);
    }

    public static function jornada(?float $horasJornada): float
    {
        return $horasJornada !== null && $horasJornada > 0 && $horasJornada < self::JORNADA_BASE ? round($horasJornada, 4) : self::JORNADA_BASE;
    }

    /** Promedio de horas programadas por día laborable del período (null si Asistencia no tiene datos). */
    public static function jornadaPromedio(int $colaboradorId, string $desde, string $hasta): ?float
    {
        $minutos = AsistenciaResultadoDiario::withoutGlobalScopes()->where('colaborador_id', $colaboradorId)
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hasta)->where('minutos_programados', '>', 0)
            ->pluck('minutos_programados');

        return $minutos->isEmpty() ? null : round($minutos->avg() / 60, 4);
    }

    public static function formula(float $sueldoBasico, float $asignacionFamiliar, ?float $horasJornada): string
    {
        $jornada = self::jornada($horasJornada);
        $remuneracion = $asignacionFamiliar > 0 ? "({$sueldoBasico} + AF {$asignacionFamiliar})" : (string) $sueldoBasico;

        return "{$remuneracion} / 30 / {$jornada} h".(self::divisorProvisional($horasJornada) ? ' [jornada real '.round($horasJornada, 2).' h: se usa 8 h provisionalmente]' : '');
    }

    /** Jornada promedio mayor a 8 h: el divisor de 8 h es provisional (pendiente de validación laboral). */
    public static function divisorProvisional(?float $horasJornada): bool
    {
        return $horasJornada !== null && $horasJornada > self::JORNADA_BASE;
    }

    public static function avisoProvisional(?float $horasJornada): ?string
    {
        return self::divisorProvisional($horasJornada)
            ? 'Jornada promedio de '.round($horasJornada, 2).' h: el valor hora de las horas extra se calcula provisionalmente con 8 h (D.S. 007-2002-TR art. 12; criterio para jornadas mayores a 8 h pendiente de validación laboral).'
            : null;
    }
}
