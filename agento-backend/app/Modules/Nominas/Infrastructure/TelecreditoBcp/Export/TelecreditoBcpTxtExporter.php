<?php

namespace App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpChecksumCalculator;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpExportException;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpHeaderBuilder;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpPagoBuilder;
use App\Modules\Nominas\Models\Boleta;
use Illuminate\Support\Collection;

/**
 * Orquesta CABECERA + PAGOS + CHECKSUM en el archivo final (Sección 3/29
 * del encargo del exportador). Nunca implementa reglas de campo propias
 * — eso vive en los Builders — solo ensambla y hace el resguardo final de
 * longitud/flag IDC antes de devolver cualquier contenido.
 */
final class TelecreditoBcpTxtExporter
{
    private const POSICION_FLAG_IDC = 194; // posición humana 195, índice base cero 194

    private const VALOR_FLAG_IDC_ESPERADO = 'S';

    /**
     * @param  Collection<int, Boleta>  $boletas  Con `datosPago.banco` ya precargado.
     */
    public static function generar(
        EmpresaCuentaBancaria $cuentaCargo,
        string $fechaProcesoAaaammdd,
        string $subtipo,
        string $referenciaPlanilla,
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
        $numeroDetalle = 0;
        foreach ($boletas as $boleta) {
            $numeroDetalle++;
            $detalle = TelecreditoBcpPagoBuilder::construir($boleta);

            // mb_substr, NO acceso por índice ($detalle[194]): ese acceso es
            // por BYTE, y con un nombre/referencia que trae Ñ antes de esta
            // posición, el byte 194 ya no coincide con el carácter 195 —
            // la línea sigue en UTF-8 en este punto del pipeline.
            if (mb_substr($detalle, self::POSICION_FLAG_IDC, 1) !== self::VALOR_FLAG_IDC_ESPERADO) {
                throw TelecreditoBcpExportException::detalleEstructuralmenteInvalido(
                    $numeroDetalle,
                    "posición 195 debe contener '".self::VALOR_FLAG_IDC_ESPERADO."'.",
                );
            }

            $lineas[] = $detalle;
        }

        $texto = implode(TelecreditoBcpTxtFormatter::LINE_ENDING, $lineas);
        if ($lineas !== []) {
            $texto .= TelecreditoBcpTxtFormatter::LINE_ENDING;
        }

        $textoConvertido = TelecreditoBcpTxtFormatter::convertirLinea($texto);

        // Resguardo final (Sección 18 del encargo V2): verifica los BYTES
        // que realmente se van a escribir en el archivo, ya en
        // Windows-1252 — nunca los de la cadena intermedia en UTF-8. En
        // Windows-1252 (1 byte = 1 carácter) esto debe coincidir siempre
        // con lo ya validado por mb_strlen más arriba; si no coincide,
        // algo en la conversión de encoding se comportó de forma
        // inesperada (ej. un carácter fuera del repertorio de
        // Windows-1252) y no se debe emitir un archivo mal formado.
        self::verificarBytesFinales($textoConvertido);

        return $textoConvertido;
    }

    private static function verificarBytesFinales(string $textoConvertido): void
    {
        $lineasFinales = explode(TelecreditoBcpTxtFormatter::LINE_ENDING, rtrim($textoConvertido, TelecreditoBcpTxtFormatter::LINE_ENDING));

        if (strlen($lineasFinales[0] ?? '') !== TelecreditoBcpHeaderBuilder::LONGITUD_TOTAL) {
            throw TelecreditoBcpExportException::longitudLineaIncorrecta(
                'CABECERA (bytes finales tras convertir a '.TelecreditoBcpTxtFormatter::ENCODING.')',
                strlen($lineasFinales[0] ?? ''),
                TelecreditoBcpHeaderBuilder::LONGITUD_TOTAL,
            );
        }

        foreach (array_slice($lineasFinales, 1) as $indice => $detalleFinal) {
            if (strlen($detalleFinal) !== TelecreditoBcpPagoBuilder::LONGITUD_TOTAL) {
                throw TelecreditoBcpExportException::detalleEstructuralmenteInvalido(
                    $indice + 1,
                    'longitud final en bytes incorrecta tras convertir el encoding ('.strlen($detalleFinal).' bytes, esperados '.TelecreditoBcpPagoBuilder::LONGITUD_TOTAL.').',
                );
            }
        }
    }
}
