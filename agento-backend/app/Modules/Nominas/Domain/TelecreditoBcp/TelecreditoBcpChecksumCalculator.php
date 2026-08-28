<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

/**
 * Algoritmo de checksum confirmado del PDF (página 2, campo 10) y
 * verificado a mano contra su propio ejemplo:
 *   Cuenta BCP 1910008791097 → quitar 3 primeros dígitos → 0008791097
 *   CCI       00963200000380987111 → quitar 10 primeros dígitos → 0380987111
 *   Cargo     1911000152155 → quitar 3 primeros dígitos → 1000152155
 *   CHECKSUM = 8791097 + 380987111 + 1000152155 = 1389930363 ✓ (coincide con el PDF)
 *
 * Suma NUMÉRICA con BCMath (Sección 27: "nunca float") — la suma de
 * muchas cuentas de 10 dígitos puede exceder el rango seguro de un float
 * de 64 bits en una planilla grande.
 *
 * La POSICIÓN final de este campo en el TXT (99 vs. el 70 que imprime el
 * PDF) es una decisión aparte, documentada en TelecreditoBcpHeaderBuilder
 * — este calculador solo produce el VALOR, no decide dónde va.
 */
final class TelecreditoBcpChecksumCalculator
{
    private const DIGITOS_A_QUITAR_BCP = 3;

    private const DIGITOS_A_QUITAR_INTERBANCARIA = 10;

    /**
     * @param  array<int, array{esBcp: bool, cuenta: string}>  $cuentasAbono  Ya reducidas a numero_cuenta (si BCP) o cci (si no).
     */
    public static function calcular(string $numeroCuentaCargo, array $cuentasAbono): string
    {
        $suma = self::reducirCargo($numeroCuentaCargo);

        foreach ($cuentasAbono as $abono) {
            $reducida = $abono['esBcp']
                ? self::reducirBcp($abono['cuenta'])
                : self::reducirInterbancaria($abono['cuenta']);

            $suma = bcadd($suma, $reducida);
        }

        return $suma;
    }

    private static function reducirBcp(string $cuenta): string
    {
        return substr($cuenta, self::DIGITOS_A_QUITAR_BCP) ?: '0';
    }

    private static function reducirInterbancaria(string $cuenta): string
    {
        return substr($cuenta, self::DIGITOS_A_QUITAR_INTERBANCARIA) ?: '0';
    }

    private static function reducirCargo(string $cuenta): string
    {
        return substr($cuenta, self::DIGITOS_A_QUITAR_BCP) ?: '0';
    }
}
