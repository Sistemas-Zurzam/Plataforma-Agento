<?php

namespace App\Modules\Nominas\Support;

use Illuminate\Support\Carbon;

/**
 * Separa el mes que se paga del rango de incidencias que se descuenta.
 *
 * Un ciclo cuyo corte cae antes de su fecha_fin es un ciclo con "corte
 * diferido": septiembre pagará el mes completo, pero sus faltas/tardanzas
 * se leen desde el día posterior al corte anterior hasta el corte actual.
 * Si el corte coincide con el final del ciclo se conserva el comportamiento
 * histórico: se usa el mismo mes calendario para pago y asistencia.
 */
final class PeriodoAsistenciaCiclo
{
    /** @return array{inicio: string, fin: string} */
    public static function resolver(string $fechaInicioCiclo, string $fechaFinCiclo, string $fechaCorte): array
    {
        $inicio = Carbon::parse($fechaInicioCiclo)->startOfDay();
        $fin = Carbon::parse($fechaFinCiclo)->startOfDay();
        $corte = Carbon::parse($fechaCorte)->startOfDay();

        // Un corte al cierre del ciclo mantiene los ciclos ya existentes tal
        // cual. El modo diferido se activa expresamente al elegir, por
        // ejemplo, el día 27 en un ciclo que termina el 30/31.
        if ($corte->gte($fin)) {
            return ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()];
        }

        return [
            'inicio' => $corte->copy()->subMonthNoOverflow()->addDay()->toDateString(),
            'fin' => $corte->toDateString(),
        ];
    }

    /** @return array{inicio: string, fin: string} */
    public static function resolverHorasExtraManual(string $fechaInicioCiclo, string $fechaFinCiclo, string $fechaCorte): array
    {
        $inicio = Carbon::parse($fechaInicioCiclo)->startOfDay();
        $fin = Carbon::parse($fechaFinCiclo)->startOfDay();
        $corte = Carbon::parse($fechaCorte)->startOfDay();

        if ($corte->gte($fin)) {
            return ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()];
        }

        return [
            // Se admite el día de corte del mes anterior para registrar HE
            // manual que no quedó en el huellero.
            'inicio' => $corte->copy()->subMonthNoOverflow()->toDateString(),
            'fin' => $corte->toDateString(),
        ];
    }

    /**
     * Proyectado de planilla: la asistencia se lee solo hasta el último día
     * ya procesado; el resto del mes se paga sin incidencias (proyectado al
     * sueldo). El mes que se paga no cambia — solo se recorta la lectura.
     *
     * @param  array{inicio: string, fin: string}  $periodo  Resultado de resolver().
     * @return array{inicio: string, fin: string}
     */
    public static function acotar(array $periodo, ?string $finAsistencia): array
    {
        if ($finAsistencia !== null && $finAsistencia < $periodo['fin']) {
            $periodo['fin'] = $finAsistencia;
        }

        return $periodo;
    }
}
