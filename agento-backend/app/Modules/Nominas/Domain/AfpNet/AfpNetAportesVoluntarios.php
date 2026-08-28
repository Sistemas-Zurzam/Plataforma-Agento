<?php

namespace App\Modules\Nominas\Domain\AfpNet;

/**
 * Único lugar donde vive el default de los 3 campos de aporte voluntario
 * (Sección 16 del encargo) — nunca se repite "0.00" suelto en el
 * FilaBuilder ni en cada Exporter. Agento NO modela aportes voluntarios
 * con/sin fin previsional ni del empleador todavía: este es el DEFAULT
 * ACTUAL DE NEGOCIO, no una regla eterna — el día que Agento incorpore
 * estos aportes, este es el único punto que hay que tocar para que los
 * Exporters empiecen a consumir el valor real.
 */
final class AfpNetAportesVoluntarios
{
    public const DEFAULT_ACTUAL = '0.00';

    public static function conFinPrevisional(): string
    {
        return self::DEFAULT_ACTUAL;
    }

    public static function sinFinPrevisional(): string
    {
        return self::DEFAULT_ACTUAL;
    }

    public static function delEmpleador(): string
    {
        return self::DEFAULT_ACTUAL;
    }
}
