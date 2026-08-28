<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use RuntimeException;

/**
 * Último resguardo de los Exporters AFPnet: si AfpNetValidator ya marcó el
 * ciclo como "listo" pero, al serializar, un dato requerido de todas
 * formas resulta faltante o fuera de rango, esto NUNCA se silencia ni se
 * trunca — se lanza esta excepción de dominio en vez de un error genérico
 * de PHP o de inventar/truncar el valor. Completamente separada de
 * PlameExportException (PLAME).
 */
class AfpNetExportException extends RuntimeException
{
    public static function campoRequeridoFaltante(string $campo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("Falta el campo requerido \"{$campo}\" para exportar AFPnet{$sufijo}.");
    }

    public static function valorExcedeLongitud(string $campo, string $valor, int $maximo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("El campo \"{$campo}\" (\"{$valor}\") excede la longitud máxima de {$maximo} caracteres para AFPnet{$sufijo} — no se trunca sin confirmación.");
    }

    public static function formatoInvalido(string $campo, string $valor, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("El campo \"{$campo}\" tiene un valor con formato inválido: \"{$valor}\"{$sufijo}.");
    }
}
