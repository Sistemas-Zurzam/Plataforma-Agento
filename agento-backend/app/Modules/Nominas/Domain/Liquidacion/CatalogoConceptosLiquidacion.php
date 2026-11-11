<?php

namespace App\Modules\Nominas\Domain\Liquidacion;

/**
 * Conceptos que admite una liquidación de beneficios por cese. El tipo
 * (ingreso/egreso/aportacion) siempre sale de aquí, nunca del cliente.
 * Una "aportacion" es costo del empleador: se muestra aparte y no entra al
 * neto del trabajador.
 *
 * Afectación por concepto (texto oficial verificado):
 *  - `afecto_aportes` (AFP/ONP) y `afecto_essalud`: solo lo remunerativo.
 *    Gratificaciones y su bonificación: inafectas a aportes (Ley 30334
 *    art. 1 y 3). Indemnización vacacional: "no está sujeta a pago o
 *    retención de ninguna aportación, contribución o tributo" (D.Leg. 713
 *    art. 23). CTS e indemnizaciones laborales: inafectas a renta (TUO LIR
 *    art. 18, 2.º párrafo, incisos a y c).
 *  - `afecto_5ta`: TUO LIR art. 34 inc. a (sueldos, gratificaciones,
 *    bonificaciones); la bonificación extraordinaria "sólo se encuentra
 *    afecta al impuesto a la renta de quinta categoría" (D.S. 007-2009-TR
 *    art. 5).
 */
final class CatalogoConceptosLiquidacion
{
    public const CONCEPTOS = [
        'GRATIFICACION_TRUNCA' => ['nombre' => 'Gratificación trunca', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => true],
        'BONIFICACION_EXTRAORDINARIA_TRUNCA' => ['nombre' => 'Bonificación extraordinaria', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => true],
        'GRATIFICACION_SEMESTRE_ANTERIOR' => ['nombre' => 'Gratificación proporcional del semestre cerrado', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => true],
        'BONIFICACION_EXTRAORDINARIA_SEMESTRE_ANTERIOR' => ['nombre' => 'Bonificación extraordinaria del semestre cerrado', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => true],
        'CTS_TRUNCA' => ['nombre' => 'CTS trunca', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'CTS_SEMESTRE_CERRADO' => ['nombre' => 'CTS del semestre cerrado no depositada', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'VACACIONES_TRUNCAS' => ['nombre' => 'Vacaciones truncas', 'tipo' => 'ingreso', 'afecto_aportes' => true, 'afecto_essalud' => true, 'afecto_5ta' => true],
        'VACACIONES_PENDIENTES' => ['nombre' => 'Vacaciones no gozadas', 'tipo' => 'ingreso', 'afecto_aportes' => true, 'afecto_essalud' => true, 'afecto_5ta' => true],
        'INDEMNIZACION_VACACIONAL' => ['nombre' => 'Indemnización vacacional', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'INDEMNIZACION_DESPIDO' => ['nombre' => 'Indemnización por despido arbitrario', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'DEVOLUCION_RENTA_5TA' => ['nombre' => 'Devolución de retención de 5ta en exceso', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'OTRO_INGRESO' => ['nombre' => 'Otro ingreso', 'tipo' => 'ingreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'ONP' => ['nombre' => 'ONP', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'AFP_APORTE_OBLIGATORIO' => ['nombre' => 'AFP — aporte obligatorio', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'AFP_PRIMA_SEGURO' => ['nombre' => 'AFP — prima de seguro', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'AFP_COMISION' => ['nombre' => 'AFP — comisión', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'RENTA_5TA' => ['nombre' => 'Renta de 5ta categoría', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'ADELANTO' => ['nombre' => 'Adelanto', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'PRESTAMO' => ['nombre' => 'Préstamo', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'RETENCION_JUDICIAL' => ['nombre' => 'Retención judicial', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'OTRO_DESCUENTO' => ['nombre' => 'Otro descuento', 'tipo' => 'egreso', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
        'ESSALUD' => ['nombre' => 'EsSalud (aporte del empleador)', 'tipo' => 'aportacion', 'afecto_aportes' => false, 'afecto_essalud' => false, 'afecto_5ta' => false],
    ];

    /** Conceptos que admiten un nombre libre (pueden repetirse con distinto detalle). */
    public const CON_NOMBRE_LIBRE = ['OTRO_INGRESO', 'OTRO_DESCUENTO', 'ADELANTO', 'PRESTAMO', 'RETENCION_JUDICIAL'];

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

    public static function afecto(string $codigo, string $afectacion): bool
    {
        return (bool) (self::CONCEPTOS[$codigo][$afectacion] ?? false);
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
