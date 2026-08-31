<?php

namespace App\Modules\Nominas\Infrastructure\BbvaNetCash\Export;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashDetalleBuilder;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashHeaderBuilder;
use App\Modules\Nominas\Models\Boleta;
use Illuminate\Support\Collection;

/**
 * Orquesta CABECERA + DETALLES en el archivo final. Nunca implementa
 * reglas de campo propias — eso vive en los Builders — solo ensambla con
 * el salto de línea confirmado (LF puro) y convierte a Windows-1252 al
 * final, una sola vez, sobre el texto completo.
 *
 * Sin TRAILER (confirmado: el macro nunca escribe una línea de cierre) y
 * sin salto de línea final tras el último detalle (confirmado: `Grabar()`
 * antepone `Chr(10)` a cada línea salvo la primera, nunca lo agrega al
 * final).
 */
final class BbvaNetCashTxtExporter
{
    /**
     * @param  Collection<int, Boleta>  $boletas  Con `datosPago.banco` ya precargado.
     */
    public static function generar(
        EmpresaCuentaBancaria $cuentaCargo,
        string $subtipo,
        string $referencia,
        Collection $boletas,
    ): string {
        $montoTotal = $boletas->reduce(fn (string $acc, Boleta $b) => bcadd($acc, (string) $b->neto_a_pagar, 2), '0.00');

        $header = BbvaNetCashHeaderBuilder::construir(
            $cuentaCargo,
            $subtipo,
            $boletas->count(),
            $montoTotal,
            $referencia,
        );

        $lineas = [$header];
        foreach ($boletas as $boleta) {
            $lineas[] = BbvaNetCashDetalleBuilder::construir($boleta, $referencia);
        }

        $texto = implode(BbvaNetCashTxtFormatter::LINE_ENDING, $lineas);

        return BbvaNetCashTxtFormatter::convertir($texto);
    }
}
