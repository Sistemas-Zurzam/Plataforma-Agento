<?php

namespace App\Modules\Nominas\Infrastructure\BbvaNetCash\Export;

use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportException;

/**
 * Formatter de longitud fija BBVA Net Cash — completamente propio, NUNCA
 * reutiliza TelecreditoBcpTxtFormatter (cada integración bancaria aísla su
 * propia decisión de formato).
 *
 * A diferencia de Telecrédito BCP (que RECHAZA un valor que excede su
 * longitud), el macro oficial BBVA SÍ trunca en silencio: `Utilitario.
 * CompletarIzq`/`CompletarDer` cortan con `Mid()` sin avisar. Esto está
 * confirmado leyendo el VBA, no es una decisión de Agento — se replica
 * exactamente esa regla acá (ver docs/bbva/reference/).
 *
 * ENCODING: `Grabar()` escribe con `OpenAsTextStream(2, -2)` — modo
 * TristateUseDefault, que resuelve al codepage ANSI del sistema
 * (Windows-1252 en un Windows en español). Consistente con que
 * `RemplazaAcento` del macro deja intactas á/é/í/ó/ú/ñ/Ñ (solo reemplaza
 * vocales con circunflejo/diéresis tipo à/â/ä, que no existen en textos en
 * español) — evidencia indirecta pero fuerte de que el archivo NO es
 * UTF-8. Alta confianza, no verificación binaria directa contra un TXT
 * real generado por Excel.
 *
 * LINE_ENDING: `Grabar()` escribe cada línea con `Chr(10)` puro (nunca
 * `Chr(13) & Chr(10)`) — confirmado literalmente en el VBA, sin salto final
 * tras la última línea.
 */
final class BbvaNetCashTxtFormatter
{
    /** Confirmado por evidencia indirecta fuerte del VBA — ver docblock de la clase. */
    public const ENCODING = 'Windows-1252';

    /** Confirmado literal en el VBA (`Chr(10)`, nunca `Chr(13) & Chr(10)`). */
    public const LINE_ENDING = "\n";

    /**
     * Réplica de `Utilitario.CompletarIzq`: alinea a la izquierda,
     * rellena con `$relleno` a la derecha, y si excede la longitud
     * TRUNCA en silencio conservando los primeros `$longitud` caracteres
     * (nunca lanza excepción — comportamiento confirmado del macro).
     */
    public static function izquierda(string $valor, int $longitud, string $relleno = ' '): string
    {
        $actual = mb_strlen($valor);
        if ($actual >= $longitud) {
            return mb_substr($valor, 0, $longitud);
        }

        return $valor.str_repeat($relleno, $longitud - $actual);
    }

    /**
     * Réplica de `Utilitario.CompletarDer`: alinea a la derecha, rellena
     * con `$relleno` a la izquierda, y si excede la longitud TRUNCA en
     * silencio conservando los últimos `$longitud` caracteres (mismo
     * comportamiento que el macro).
     *
     * Aritmética por CARACTER (`mb_strlen`/`mb_substr`), no por byte: un
     * nombre con Ñ/tildes en UTF-8 ocupa más bytes que caracteres, y esta
     * clase debe garantizar longitud fija en caracteres antes de la
     * conversión final a Windows-1252 (que sí es 1 byte = 1 carácter).
     */
    public static function derecha(string $valor, int $longitud, string $relleno = '0'): string
    {
        $actual = mb_strlen($valor);
        if ($actual >= $longitud) {
            return mb_substr($valor, $actual - $longitud, $longitud);
        }

        return str_repeat($relleno, $longitud - $actual).$valor;
    }

    /**
     * Código de longitud EXACTA para valores que Agento controla por
     * completo (tipo de registro, tipo de abono, tipo de documento): un
     * desajuste acá es un error de mapeo interno, no un dato de entrada —
     * por eso SÍ lanza excepción (a diferencia de izquierda()/derecha()).
     */
    public static function codigoFijo(string $valor, int $longitud, string $campo): string
    {
        if (strlen($valor) !== $longitud) {
            throw BbvaNetCashExportException::formatoInvalido($campo, $valor);
        }

        return $valor;
    }

    /**
     * Réplica de `Utilitario.FormatoImporte`: elimina el punto decimal y
     * deja exactamente 2 dígitos decimales pegados al entero — sin punto,
     * sin redondeo (el macro TRUNCA un tercer decimal con `Mid()`, nunca
     * redondea). Devuelve solo los dígitos; el zero-padding a 15
     * posiciones lo aplica quien llama, vía derecha().
     *
     * Trabaja sobre el string decimal del snapshot (nunca FLOAT), mismo
     * criterio que TelecreditoBcpTxtFormatter::importe().
     */
    public static function formatoImporte(string $valorDecimal, string $campo, ?int $colaboradorId = null): string
    {
        if (str_starts_with($valorDecimal, '-')) {
            throw BbvaNetCashExportException::formatoInvalido($campo, $valorDecimal, $colaboradorId);
        }

        [$entero, $decimal] = array_pad(explode('.', $valorDecimal, 2), 2, '');
        $entero = $entero === '' ? '0' : $entero;
        $decimal = substr(str_pad($decimal, 2, '0', STR_PAD_RIGHT), 0, 2);

        return $entero.$decimal;
    }

    public static function convertir(string $texto): string
    {
        return mb_convert_encoding($texto, self::ENCODING, 'UTF-8');
    }
}
