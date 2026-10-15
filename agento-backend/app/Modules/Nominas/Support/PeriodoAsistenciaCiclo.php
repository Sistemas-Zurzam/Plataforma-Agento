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
}
