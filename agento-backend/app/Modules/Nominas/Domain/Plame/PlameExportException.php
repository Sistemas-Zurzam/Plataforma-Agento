<?php

namespace App\Modules\Nominas\Domain\Plame;

use RuntimeException;

/**
 * Último resguardo de los Generators (Sección 21/72 del encargo): si
 * PlameValidator ya marcó un archivo como "listo" pero, al momento de
 * serializar, un dato requerido de todas formas resulta faltante/fuera de
 * rango, esto NUNCA debe silenciarse ni truncarse — se lanza esta excepción
 * de dominio en vez de un error genérico de PHP (Undefined index, etc.) o de
 * inventar/truncar el valor.
 */
class PlameExportException extends RuntimeException
{
    public static function mapeoSunatFaltante(string $tipo, string $claveInterna): self
    {
        return new self("No hay código SUNAT configurado para \"{$tipo}\" = \"{$claveInterna}\" (Catálogos SUNAT).");
    }

    public static function valorFueraDeRango(string $campo, int|string $valor, string $limite): self
    {
        return new self("El campo \"{$campo}\" tiene el valor \"{$valor}\", que excede el límite permitido por SUNAT ({$limite}).");
    }

    public static function campoRequeridoFaltante(string $campo, ?int $colaboradorId = null): self
    {
        $sufijo = $colaboradorId ? " (colaborador_id={$colaboradorId})" : '';

        return new self("Falta el campo requerido \"{$campo}\" para exportar PLAME{$sufijo}.");
    }

    public static function formatoInvalido(string $campo, string $valor): self
    {
        return new self("El campo \"{$campo}\" tiene un valor con formato inválido: \"{$valor}\".");
    }
}
