<?php

namespace App\Modules\Nominas\Infrastructure\AfpNet\Export;

use App\Modules\Nominas\Domain\AfpNet\AfpNetExportException;

/**
 * Formatter de longitud fija AFPnet — completamente propio, NUNCA
 * reutiliza PlameTxtSerializer (Sección 23/31 del encargo: AFPnet TXT no
 * usa separadores, PLAME sí; los formatos de importe son distintos).
 *
 * Reglas (Sección 25/26/27 del encargo):
 *  - Campos A(n): alfanumérico, alineado a la izquierda, relleno con
 *    espacios a la derecha hasta longitud exacta. TRUNCAMIENTO PROHIBIDO
 *    cuando el dato excede el máximo — se lanza excepción, nunca se
 *    recorta un nombre sin confirmar que AFPnet lo permite.
 *  - Campos 9(n): numérico entero, alineado a la derecha, relleno con
 *    ceros a la izquierda hasta longitud exacta.
 *  - Importes 9(n).9(m): sin punto decimal en el TXT — se concatenan la
 *    parte entera y decimal y se rellena con ceros a la izquierda hasta
 *    la longitud total (ej. "600.20" con 9(7).9(2) → "000060020").
 *
 * Encoding: NO se reutiliza automáticamente la decisión de PLAME
 * (ISO-8859-1) — se aísla acá mismo (Sección 28/31): no hay evidencia de
 * que AFPnet exija un encoding distinto a UTF-8, y no hay razón para
 * eliminar Ñ/tildes de apellidos/nombres sin esa evidencia. Si la carga
 * real en AFPnet exige otro encoding, este es el único lugar que hay que
 * tocar.
 */
final class AfpNetTxtFormatter
{
    public const ENCODING = 'UTF-8';

    /**
     * Campo A(n) — nunca trunca; longitud excedida es un error, no un
     * recorte silencioso.
     */
    public static function texto(string $valor, int $longitud, string $campo, ?int $colaboradorId = null): string
    {
        if (mb_strlen($valor) > $longitud) {
            throw AfpNetExportException::valorExcedeLongitud($campo, $valor, $longitud, $colaboradorId);
        }

        return str_pad($valor, $longitud, ' ', STR_PAD_RIGHT);
    }

    /**
     * Campo 9(n) — numérico entero relleno con ceros a la izquierda.
     */
    public static function numeroEntero(int $valor, int $longitud, string $campo): string
    {
        $texto = (string) $valor;
        if (strlen($texto) > $longitud) {
            throw AfpNetExportException::valorExcedeLongitud($campo, $texto, $longitud);
        }

        return str_pad($texto, $longitud, '0', STR_PAD_LEFT);
    }

    /**
     * Importe 9(n).9(m) — sin punto decimal en el TXT (Sección 26): la
     * parte entera y decimal se concatenan y se rellenan con ceros a la
     * izquierda. Trabaja sobre el string decimal del snapshot (nunca
     * FLOAT) — "600.20" con enteros=7/decimales=2 → "000060020".
     */
    public static function importe(string $valorDecimal, int $digitosEnteros, int $digitosDecimales, string $campo, ?int $colaboradorId = null): string
    {
        if (str_starts_with($valorDecimal, '-')) {
            throw AfpNetExportException::formatoInvalido($campo, $valorDecimal, $colaboradorId);
        }

        [$entero, $decimal] = array_pad(explode('.', $valorDecimal, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, $digitosDecimales), $digitosDecimales, '0', STR_PAD_RIGHT);

        if (strlen($entero) > $digitosEnteros) {
            throw AfpNetExportException::valorExcedeLongitud($campo, $valorDecimal, $digitosEnteros + $digitosDecimales, $colaboradorId);
        }

        return str_pad($entero, $digitosEnteros, '0', STR_PAD_LEFT).$decimal;
    }
}
