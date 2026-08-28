<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

use RuntimeException;

/**
 * Último resguardo del exportador Telecrédito BCP: si el Validator ya
 * marcó todo como listo pero, al serializar, un dato requerido resulta
 * faltante/fuera de rango, o una línea no mide exactamente lo que exige
 * el formato (113/195), esto NUNCA se emite parcialmente — se lanza esta
 * excepción de dominio. Completamente separada de PlameExportException y
 * AfpNetExportException.
 */
class TelecreditoBcpExportException extends RuntimeException
{
    public static function campoRequeridoFaltante(string $campo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("Falta el campo requerido \"{$campo}\" para exportar Telecrédito BCP{$sufijo}.");
    }

    public static function valorExcedeLongitud(string $campo, string $valor, int $maximo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("El campo \"{$campo}\" (\"{$valor}\") excede la longitud máxima de {$maximo} caracteres{$sufijo} — no se trunca sin confirmación.");
    }

    public static function formatoInvalido(string $campo, string $valor, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("El campo \"{$campo}\" tiene un valor con formato inválido: \"{$valor}\"{$sufijo}.");
    }

    public static function longitudLineaIncorrecta(string $tipoLinea, int $longitudObtenida, int $longitudEsperada): self
    {
        return new self("Línea {$tipoLinea} con longitud incorrecta: {$longitudObtenida} (esperado {$longitudEsperada}). No se emite un archivo mal formado.");
    }
}
