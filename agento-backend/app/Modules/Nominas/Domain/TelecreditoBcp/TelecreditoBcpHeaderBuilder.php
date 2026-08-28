<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export\TelecreditoBcpTxtFormatter;

/**
 * Estructura CABECERA — confirmada campo por campo del PDF (página 2),
 * 113 caracteres exactos:
 *
 *   1-1     Tipo de registro       fijo "1"
 *   2-7     Cantidad de abonos     9(6), derecha, ceros
 *   8-15    Fecha de proceso       AAAAMMDD
 *   16-16   Subtipo de planilla    A(1)
 *   17-17   Tipo cuenta de cargo   A(1)  — C/M
 *   18-21   Moneda cuenta de cargo A(4)  — 0001/1001
 *   22-41   Número cuenta de cargo A(20), izquierda, espacios
 *   42-58   Monto total            9(14).99, derecha, ceros (17 caracteres)
 *   59-98   Referencia planilla    A(40), espacios
 *   99-113  Checksum               9(15), derecha, ceros
 *
 * POSICIÓN DEL CHECKSUM (Sección 4 del encargo): el PDF imprime posición
 * 70/longitud 15 para este campo, lo cual es estructuralmente imposible
 * (se solapa con Referencia [59-98] y no da un total de 113). La ÚNICA
 * posición internamente consistente — confirmada sumando las longitudes
 * de los 9 campos anteriores (98) y contra el total declarado de 113 — es
 * 99-113. Se implementa 99. INTERPRETACIÓN ESTRUCTURAL PENDIENTE DE
 * HOMOLOGACIÓN BCP — no se oculta esta decisión, ver entrega final.
 *
 * MONTO TOTAL — otra inconsistencia menor del PDF: el patrón impreso
 * "XXXXXXXXXXXXXXX.YY" mide 18 caracteres (15 X + punto + 2 Y), pero la
 * Longitud declarada y el propio ejemplo ("00000000001200.00") miden 17.
 * Se confía en el ejemplo + la longitud declarada (14 dígitos enteros +
 * punto + 2 decimales = 17), no en el patrón con X de más — mismo
 * criterio que la Sección 4: nunca ocultar la inconsistencia, documentarla.
 */
final class TelecreditoBcpHeaderBuilder
{
    private const TIPO_REGISTRO = '1';

    private const LONGITUD_TOTAL = 113;

    public static function construir(
        EmpresaCuentaBancaria $cuentaCargo,
        string $fechaProcesoAaaammdd,
        string $subtipo,
        int $cantidadAbonos,
        string $montoTotal,
        string $referenciaPlanilla,
        string $checksum,
    ): string {
        $codigoTipoCuenta = TelecreditoBcpFormato::codigoTipoCuentaCargo($cuentaCargo->tipo_cuenta);
        $codigoMoneda = TelecreditoBcpFormato::codigoMoneda($cuentaCargo->moneda);

        if (blank($codigoTipoCuenta) || blank($codigoMoneda)) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('tipo_cuenta/moneda de la cuenta de cargo');
        }

        $linea =
            TelecreditoBcpTxtFormatter::codigoFijo(self::TIPO_REGISTRO, 1, 'tipo_registro')
            .TelecreditoBcpTxtFormatter::numeroEntero($cantidadAbonos, 6, 'cantidad_abonos')
            .TelecreditoBcpTxtFormatter::codigoFijo($fechaProcesoAaaammdd, 8, 'fecha_proceso')
            .TelecreditoBcpTxtFormatter::codigoFijo($subtipo, 1, 'subtipo')
            .TelecreditoBcpTxtFormatter::codigoFijo($codigoTipoCuenta, 1, 'tipo_cuenta_cargo')
            .TelecreditoBcpTxtFormatter::codigoFijo($codigoMoneda, 4, 'moneda_cargo')
            .TelecreditoBcpTxtFormatter::textoIzquierda($cuentaCargo->numero_cuenta, 20, 'numero_cuenta_cargo')
            .TelecreditoBcpTxtFormatter::importe($montoTotal, 14, 2, 'monto_total')
            .TelecreditoBcpTxtFormatter::textoIzquierda($referenciaPlanilla, 40, 'referencia_planilla')
            .TelecreditoBcpTxtFormatter::numeroEntero((int) $checksum, 15, 'checksum');

        if (strlen($linea) !== self::LONGITUD_TOTAL) {
            throw TelecreditoBcpExportException::longitudLineaIncorrecta('CABECERA', strlen($linea), self::LONGITUD_TOTAL);
        }

        return $linea;
    }
}
