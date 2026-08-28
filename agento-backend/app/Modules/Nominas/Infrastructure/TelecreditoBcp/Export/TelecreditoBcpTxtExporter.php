<?php

namespace App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpChecksumCalculator;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpHeaderBuilder;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpPagoBuilder;
use App\Modules\Nominas\Models\Boleta;
use Illuminate\Support\Collection;

/**
 * Orquesta CABECERA + PAGOS + CHECKSUM en el archivo final (Sección 3/29
 * del encargo del exportador). Nunca implementa reglas de campo propias
 * — eso vive en los Builders — solo ensambla y hace el resguardo final de
 * longitud antes de devolver cualquier contenido.
 */
final class TelecreditoBcpTxtExporter
{
    /**
     * @param  Collection<int, Boleta>  $boletas  Con `datosPago.banco` ya precargado.
     */
    public static function generar(
        EmpresaCuentaBancaria $cuentaCargo,
        string $fechaProcesoAaaammdd,
        string $subtipo,
        string $referenciaPlanilla,
        string $referenciaBeneficiario,
        string $referenciaEmpresa,
        Collection $boletas,
    ): string {
        $montoTotal = $boletas->reduce(fn (string $acc, Boleta $b) => bcadd($acc, (string) $b->neto_a_pagar, 2), '0.00');

        $checksum = TelecreditoBcpChecksumCalculator::calcular(
            $cuentaCargo->numero_cuenta,
            $boletas->map(function (Boleta $boleta) {
                $datosPago = $boleta->datosPago;
                $esBcp = $datosPago?->banco?->codigo === 'bcp';

                return [
                    'esBcp' => $esBcp,
                    'cuenta' => $esBcp ? $datosPago?->numero_cuenta_snapshot : $datosPago?->cci_snapshot,
                ];
            })->all(),
        );

        $header = TelecreditoBcpHeaderBuilder::construir(
            $cuentaCargo,
            $fechaProcesoAaaammdd,
            $subtipo,
            $boletas->count(),
            $montoTotal,
            $referenciaPlanilla,
            $checksum,
        );

        $lineas = [$header];
        foreach ($boletas as $boleta) {
            $lineas[] = TelecreditoBcpPagoBuilder::construir($boleta, $referenciaBeneficiario, $referenciaEmpresa);
        }

        $texto = implode(TelecreditoBcpTxtFormatter::LINE_ENDING, $lineas);
        if ($lineas !== []) {
            $texto .= TelecreditoBcpTxtFormatter::LINE_ENDING;
        }

        return TelecreditoBcpTxtFormatter::convertirLinea($texto);
    }
}
