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
 * ENCODING: CORREGIDO — era 'UTF-8' (no-op), causaba que Telecrédito BCP
 * rechazara el archivo como "error de estructura" en cuanto un nombre
 * traía Ñ/tilde. Confirmado byte a byte contra un archivo histórico
 * válido (Livex Planilla - 40,028.83 (1).txt): ese archivo NO es UTF-8
 * válido y codifica la Ñ como 1 solo byte (0xD1), igual que
 * Windows-1252/Latin-1 — nunca como la secuencia UTF-8 de 2 bytes
 * (0xC3 0x91) que Agento emitía. La línea seguía midiendo 195 bytes
 * igual (str_pad compensa por bytes), pero para un parser de 1
 * byte=1 carácter esos 2 bytes se leen como 2 caracteres, desplazando
 * la interpretación de esa línea desde ahí — eso es el error de
 * estructura, no una diferencia de longitud total.
 *
 * LINE_ENDING: confirmado igual contra el mismo archivo histórico (CRLF).
 */
final class TelecreditoBcpTxtFormatter
{
    public const ENCODING = 'Windows-1252';

    /** PENDIENTE DE HOMOLOGACIÓN BCP — el PDF no especifica salto de línea. */
    public const LINE_ENDING = "\r\n";

    /**
     * Alineado a la izquierda, relleno de espacios a la derecha — nunca
     * trunca (Sección 23/29): un valor que excede es un error de datos
     * real, no algo que el formatter deba recortar en silencio.
     *
     * CORREGIDO (V2 del fix de Telecrédito): usaba `str_pad`, que rellena
     * contando BYTES, no caracteres. Con un valor que trae Ñ/tilde (1
     * carácter = 2 bytes en UTF-8, todavía sin convertir a este punto del
     * pipeline), `str_pad` restaba de menos y el campo terminaba con 1
     * espacio de relleno de menos — bytes correctos por pura casualidad
     * mientras la línea seguía en UTF-8, pero 1 byte corto en cuanto
     * `convertirLinea()` la pasaba a Windows-1252 (1 byte = 1 carácter).
     * Ahora se calcula el relleno explícitamente por `mb_strlen`, igual
     * que ya hace BbvaNetCashTxtFormatter.
     */
    public static function textoIzquierda(string $valor, int $longitud, string $campo, ?int $colaboradorId = null): string
    {
        $actual = mb_strlen($valor);
        if ($actual > $longitud) {
            throw TelecreditoBcpExportException::valorExcedeLongitud($campo, $valor, $longitud, $colaboradorId);
        }

        return $valor.str_repeat(' ', $longitud - $actual);
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
