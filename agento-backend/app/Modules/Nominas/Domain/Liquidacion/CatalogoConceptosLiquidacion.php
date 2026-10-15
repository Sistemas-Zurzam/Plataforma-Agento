<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

/**
 * Conceptos que admite una liquidación de beneficios por cese. El tipo
 * (ingreso/egreso) siempre sale de aquí, nunca del cliente.
 * `afecto_aportes`: el monto es remunerativo y genera AFP/ONP.
 */
final class CatalogoConceptosLiquidacion
{
    public const CONCEPTOS = [
        'GRATIFICACION_TRUNCA' => ['nombre' => 'Gratificación trunca', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'BONIFICACION_EXTRAORDINARIA_TRUNCA' => ['nombre' => 'Bonificación extraordinaria', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'CTS_TRUNCA' => ['nombre' => 'CTS trunca', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'VACACIONES_TRUNCAS' => ['nombre' => 'Vacaciones truncas', 'tipo' => 'ingreso', 'afecto_aportes' => true],
        'VACACIONES_PENDIENTES' => ['nombre' => 'Vacaciones no gozadas', 'tipo' => 'ingreso', 'afecto_aportes' => true],
        'INDEMNIZACION_VACACIONAL' => ['nombre' => 'Indemnización vacacional', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'INDEMNIZACION_DESPIDO' => ['nombre' => 'Indemnización por despido arbitrario', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'OTRO_INGRESO' => ['nombre' => 'Otro ingreso', 'tipo' => 'ingreso', 'afecto_aportes' => false],
        'ONP' => ['nombre' => 'ONP', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'AFP_APORTE_OBLIGATORIO' => ['nombre' => 'AFP — aporte obligatorio', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'AFP_PRIMA_SEGURO' => ['nombre' => 'AFP — prima de seguro', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'AFP_COMISION' => ['nombre' => 'AFP — comisión', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'RENTA_5TA' => ['nombre' => 'Renta de 5ta categoría', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'ADELANTO' => ['nombre' => 'Adelanto', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'PRESTAMO' => ['nombre' => 'Préstamo', 'tipo' => 'egreso', 'afecto_aportes' => false],
        'OTRO_DESCUENTO' => ['nombre' => 'Otro descuento', 'tipo' => 'egreso', 'afecto_aportes' => false],
    ];

    /** Conceptos que admiten un nombre libre (pueden repetirse con distinto detalle). */
    public const CON_NOMBRE_LIBRE = ['OTRO_INGRESO', 'OTRO_DESCUENTO', 'ADELANTO', 'PRESTAMO'];

    public static function existe(string $codigo): bool
    {
        return isset(self::CONCEPTOS[$codigo]);
    }

    public static function tipo(string $codigo): string
    {
        return self::CONCEPTOS[$codigo]['tipo'];
    }

    public static function nombre(string $codigo): string
    {
        return self::CONCEPTOS[$codigo]['nombre'];
    }

    /** @return array<int, array{codigo: string, nombre: string, tipo: string, nombre_libre: bool}> */
    public static function paraSeleccion(): array
    {
        return collect(self::CONCEPTOS)->map(fn ($c, $codigo) => [
            'codigo' => $codigo, 'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
            'nombre_libre' => in_array($codigo, self::CON_NOMBRE_LIBRE, true),
        ])->values()->all();
    }
}
