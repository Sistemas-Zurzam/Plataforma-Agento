<?php

namespace App\Modules\Nominas\Domain\BbvaNetCash;

use RuntimeException;

/**
 * Último resguardo del exportador BBVA Net Cash: si el Validator ya marcó
 * todo como listo pero, al serializar, una línea no mide exactamente lo
 * que exige el layout (151/233), esto NUNCA se emite parcialmente — se
 * lanza esta excepción de dominio. Completamente separada de
 * TelecreditoBcpExportException (cada integración bancaria aísla la suya).
 */
class BbvaNetCashExportException extends RuntimeException
{
    public static function campoRequeridoFaltante(string $campo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("Falta el campo requerido \"{$campo}\" para exportar BBVA Net Cash{$sufijo}.");
    }

    public static function formatoInvalido(string $campo, string $valor, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("El campo \"{$campo}\" tiene un valor con formato inválido: \"{$valor}\"{$sufijo}.");
    }

    public static function longitudLineaIncorrecta(string $tipoLinea, int $longitudObtenida, int $longitudEsperada): self
    {
        return new self("Línea {$tipoLinea} con longitud incorrecta: {$longitudObtenida} (esperado {$longitudEsperada}). No se emite un archivo BBVA Net Cash mal formado.");
    }
}
