<?php

namespace App\Modules\Asistencia\Domain;

/**
 * Regla pura del "descanso semanal flexible automático": de los días
 * candidatos (0 marcaciones, sin permiso/feriado/planificación previa) que
 * recibe, en orden cronológico, los primeros $diasDescansoRequeridos son
 * descanso y el resto falta. No conoce Eloquent, Carbon, empresas ni
 * periodos — esa resolución (qué cuenta como candidato, qué segmento de la
 * semana le toca evaluar, si el periodo sigue abierto) es responsabilidad
 * exclusiva del llamador (AsignarDescansoFlexibleSemanal). Por eso puede
 * recibir tanto la semana completa como solo un segmento (la porción de la
 * semana que cae dentro de un periodo): el resultado es el mismo siempre
 * que $diasDescansoRequeridos ya venga descontando lo asignado antes.
 */
final class DescansoFlexibleResolver
{
    /**
     * @param  array<int, array{fecha: string, esCandidato: bool}>  $dias  Días a evaluar, en orden cronológico.
     * @param  int  $diasDescansoRequeridos  Remanente pendiente para la semana (ya descontando lo asignado en días anteriores).
     * @return array<string, string> fecha => 'descanso'|'falta', solo para los días candidatos.
     */
    public static function resolver(array $dias, int $diasDescansoRequeridos): array
    {
        $candidatos = array_values(array_filter($dias, fn (array $dia): bool => $dia['esCandidato']));

        $veredictos = [];
        foreach ($candidatos as $indice => $dia) {
            $veredictos[$dia['fecha']] = $indice < $diasDescansoRequeridos ? 'descanso' : 'falta';
        }

        return $veredictos;
    }
}
