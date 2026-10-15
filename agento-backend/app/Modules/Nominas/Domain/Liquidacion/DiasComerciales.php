<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

use Illuminate\Support\Carbon;

/**
 * Cuenta días con la convención laboral de meses de 30 días (año de 360),
 * la que usan CTS y vacaciones: un semestre completo son 180 días y un año
 * 360, sin importar cuántos días calendario tenga cada mes. Ambos extremos
 * se cuentan inclusive. El último día de cualquier mes (28/29 de febrero,
 * 31) equivale al día 30.
 */
final class DiasComerciales
{
    public static function entre(Carbon $desde, Carbon $hasta): int
    {
        if ($hasta->lt($desde)) {
            return 0;
        }

        $diaDesde = $desde->isLastOfMonth() ? 30 : min($desde->day, 30);
        $diaHasta = $hasta->isLastOfMonth() ? 30 : min($hasta->day, 30);

        return ($hasta->year - $desde->year) * 360
            + ($hasta->month - $desde->month) * 30
            + ($diaHasta - $diaDesde)
            + 1;
    }
}
