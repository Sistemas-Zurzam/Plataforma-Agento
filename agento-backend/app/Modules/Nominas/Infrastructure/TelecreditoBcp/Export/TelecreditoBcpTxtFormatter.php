<?php

namespace App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export;

use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpExportException;

/**
 * Formatter de longitud fija Telecrédito BCP — completamente propio,
 * NUNCA reutiliza PlameTxtSerializer ni AfpNetTxtFormatter (Sección 30 del
 * encargo: cada integración bancaria/estatal aísla su propia decisión de
 * formato).
 *
 * Alineación EXACTA según lo que dice el PDF por campo (no es uniforme):
 *  - Campos de texto/identificador (número de cuenta, documento, nombre,
 *    referencias): alinear IZQUIERDA, rellenar con ESPACIOS a la derecha.
 *  - Campos numéricos de cantidad/monto (cantidad de abonos, monto,
 *    importe): alinear DERECHA, rellenar con CEROS a la izquierda.
 *  - Códigos fijos de longitud exacta (tipo registro, subtipo, tipo
 *    cuenta, moneda, flag IDC): se validan de longitud exacta, sin
 *    padding real necesario.
 *
 * ENCODING y LINE_ENDING: el PDF NO los especifica en ninguna parte —
 * decisión explícita de Agento, aislada acá, PENDIENTE DE HOMOLOGACIÓN
 * CON BCP (Sección 30/31 del encargo). Si la carga real falla por esto,
 * este es el único archivo que hay que tocar.
 */
final class TelecreditoBcpTxtFormatter
{
    /** PENDIENTE DE HOMOLOGACIÓN BCP — el PDF no especifica encoding. */
    public const ENCODING = 'UTF-8';

    /** PENDIENTE DE HOMOLOGACIÓN BCP — el PDF no especifica salto de línea. */
    public const LINE_ENDING = "\r\n";

    /**
     * Alineado a la izquierda, relleno de espacios a la derecha — nunca
     * trunca (Sección 23/29): un valor que excede es un error de datos
     * real, no algo que el formatter deba recortar en silencio.
     */
    public static function textoIzquierda(string $valor, int $longitud, string $campo, ?int $colaboradorId = null): string
    {
        if (mb_strlen($valor) > $longitud) {
            throw TelecreditoBcpExportException::valorExcedeLongitud($campo, $valor, $longitud, $colaboradorId);
        }

        return str_pad($valor, $longitud, ' ', STR_PAD_RIGHT);
    }

    /**
     * Alineado a la derecha, relleno de ceros a la izquierda — para
     * cantidades/códigos numéricos simples (no importes decimales, ver
     * importe() para eso).
     */
    public static function numeroEntero(int $valor, int $longitud, string $campo): string
    {
        $texto = (string) $valor;
        if (strlen($texto) > $longitud) {
            throw TelecreditoBcpExportException::valorExcedeLongitud($campo, $texto, $longitud);
        }

        return str_pad($texto, $longitud, '0', STR_PAD_LEFT);
    }

    /**
     * Código de longitud EXACTA (Sección 5/6/9/10/26): tipo de registro,
     * subtipo, tipo de cuenta, moneda, flag IDC — nunca se rellena, si no
     * mide exacto es un error de mapeo, no un dato a ajustar.
     */
    public static function codigoFijo(string $valor, int $longitud, string $campo): string
    {
        if (strlen($valor) !== $longitud) {
            throw TelecreditoBcpExportException::formatoInvalido($campo, $valor);
        }

        return $valor;
    }

    /**
     * Importe XXXXXXXXXXXXXXX.YY (Sección 13/26 — SIEMPRE con punto
     * decimal, a diferencia de AFPnet que lo omite): trabaja sobre el
     * string decimal del snapshot (nunca FLOAT), alineado a la derecha con
     * ceros a la izquierda en la parte entera.
     */
    public static function importe(string $valorDecimal, int $digitosEnteros, int $digitosDecimales, string $campo, ?int $colaboradorId = null): string
    {
        if (str_starts_with($valorDecimal, '-')) {
            throw TelecreditoBcpExportException::formatoInvalido($campo, $valorDecimal, $colaboradorId);
        }

        [$entero, $decimal] = array_pad(explode('.', $valorDecimal, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, $digitosDecimales), $digitosDecimales, '0', STR_PAD_RIGHT);

        if (strlen($entero) > $digitosEnteros) {
            throw TelecreditoBcpExportException::valorExcedeLongitud($campo, $valorDecimal, $digitosEnteros + $digitosDecimales + 1, $colaboradorId);
        }

        return str_pad($entero, $digitosEnteros, '0', STR_PAD_LEFT).'.'.$decimal;
    }

    public static function convertirLinea(string $linea): string
    {
        return mb_convert_encoding($linea, self::ENCODING, 'UTF-8');
    }
}
