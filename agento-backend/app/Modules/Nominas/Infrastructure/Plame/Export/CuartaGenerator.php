<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura E20 (.4ta) — Prestador de Servicios con Rentas de 4ta
 * categoría: Detalle de comprobantes (Sección 37).
 *
 * Campos exactos (Anexo 3, hoja "E20-PS4.Comp."), en este orden:
 *  1. Tipo de documento del prestador           (Texto, Tabla 3)
 *  2. Número de documento del prestador         (Texto, máx 15)
 *  3. Tipo del comprobante emitido               (Texto, 1, Tabla 23)
 *  4. Serie del comprobante emitido              (Alfanumérico, máx 4 — si Recibo x Honorarios o Nota de crédito)
 *  5. Número del comprobante emitido             (Texto, máx 8 — ídem)
 *  6. Monto total del servicio                   (Numérico, "12,2")
 *  7. Fecha de emisión                           (Fecha, dd/mm/aaaa)
 *  8. Fecha de pago                              (Fecha, dd/mm/aaaa)
 *  9. Indicador de Retención de Cuarta Categoría (Texto, 1: 1=SI / 0=NO)
 * 10. Indicador de Retención a Régimen Pensionario (Texto, 1: 1=ONP / 2=SPP / 3=Sin retención-No aplica)
 * 11. Importe del aporte al Régimen Pensionario  (Numérico, "7,2" — obligatorio solo si el campo 10 es "1" o "2"; vacío si es "3")
 */
final class CuartaGenerator
{
    private const TIPOS_CON_SERIE_NUMERO = ['R', 'N']; // Recibo por Honorarios, Nota de Crédito (Tabla 23)

    private const MAX_DIGITOS_ENTEROS_MONTO_SERVICIO = 12;

    private const MAX_DIGITOS_ENTEROS_APORTE = 7;

    /**
     * @return array<int, array<int, string>>
     */
    public function generar(PlameExportContext $contexto): array
    {
        return $contexto->boletasRh
            ->sortBy([
                fn (Boleta $a, Boleta $b) => $a->colaborador->tipo_documento <=> $b->colaborador->tipo_documento,
                fn (Boleta $a, Boleta $b) => $a->colaborador->numero_documento <=> $b->colaborador->numero_documento,
            ])
            ->map(fn (Boleta $boleta) => $this->registro($contexto, $boleta))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function registro(PlameExportContext $contexto, Boleta $boleta): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw PlameExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        /** @var BoletaComprobanteRh|null $comprobante */
        $comprobante = $boleta->comprobanteRh;
        if (! $comprobante) {
            throw PlameExportException::campoRequeridoFaltante('boleta_comprobante_rh', $boleta->id);
        }

        $tipoDocumento = $contexto->mapeos->codigo('tipo_documento', $colaborador->tipo_documento);

        if (blank($comprobante->tipo_comprobante)) {
            throw PlameExportException::campoRequeridoFaltante('tipo_comprobante', $colaborador->id);
        }
        // boleta_comprobantes_rh.tipo_comprobante YA guarda el código
        // oficial Tabla 23 tal cual (ver migración 000068) — se valida que
        // siga configurado/activo en Catálogos SUNAT, nunca se traduce.
        $tipoComprobante = $contexto->mapeos->codigoComprobante($comprobante->tipo_comprobante);

        [$serie, $numero] = $this->serieYNumero($comprobante, $colaborador->id);

        $montoServicio = $this->formatearMonto(
            $this->montoTotalServicio($boleta),
            self::MAX_DIGITOS_ENTEROS_MONTO_SERVICIO,
            "monto_total_servicio (colaborador_id={$colaborador->id})",
        );

        if (! $comprobante->fecha_emision || ! $comprobante->fecha_pago) {
            throw PlameExportException::campoRequeridoFaltante('fecha_emision/fecha_pago', $colaborador->id);
        }

        $indicadorRetencion4ta = $comprobante->indicador_retencion_4ta === null
            ? throw PlameExportException::campoRequeridoFaltante('indicador_retencion_4ta', $colaborador->id)
            : ($comprobante->indicador_retencion_4ta ? '1' : '0');

        $indicadorPensionario = $comprobante->indicador_retencion_regimen_pensionario;
        if (blank($indicadorPensionario) || ! in_array($indicadorPensionario, ['1', '2', '3'], true)) {
            throw PlameExportException::campoRequeridoFaltante('indicador_retencion_regimen_pensionario', $colaborador->id);
        }

        $importeAporte = $this->importeAporte($comprobante, $indicadorPensionario, $colaborador->id);

        return [
            $tipoDocumento,
            $colaborador->numero_documento,
            $tipoComprobante,
            $serie,
            $numero,
            $montoServicio,
            $comprobante->fecha_emision->format('d/m/Y'),
            $comprobante->fecha_pago->format('d/m/Y'),
            $indicadorRetencion4ta,
            $indicadorPensionario,
            $importeAporte,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function serieYNumero(BoletaComprobanteRh $comprobante, int $colaboradorId): array
    {
        if (! in_array($comprobante->tipo_comprobante, self::TIPOS_CON_SERIE_NUMERO, true)) {
            // Dieta ("D") u Otro comprobante ("O") — Anexo 3 solo exige
            // serie/número para Recibo por Honorarios y Nota de Crédito.
            return ['', ''];
        }

        if (blank($comprobante->serie) || blank($comprobante->numero)) {
            throw PlameExportException::campoRequeridoFaltante('serie/numero del comprobante', $colaboradorId);
        }

        return [$comprobante->serie, $comprobante->numero];
    }

    /**
     * Mismo criterio que BoletaService::montoTotalServicioRh() (Sección
     * 39): suma del concepto HONORARIO_BRUTO ya calculado. Se evalúa sobre
     * la colección `conceptos.concepto` ya precargada por
     * PlameExportService (Sección 68/71) en vez de invocar el método del
     * Service (que dispara una query fresca por boleta) — mismo resultado,
     * sin volver a calcular nada ni introducir N+1.
     */
    private function montoTotalServicio(Boleta $boleta): float
    {
        return (float) $boleta->conceptos
            ->filter(fn ($c) => $c->concepto?->codigo === 'HONORARIO_BRUTO')
            ->sum('monto');
    }

    private function formatearMonto(float $valor, int $maxDigitosEnteros, string $campo): string
    {
        $texto = number_format($valor, 2, '.', '');
        $parteEntera = explode('.', ltrim($texto, '-'))[0];

        if (strlen($parteEntera) > $maxDigitosEnteros) {
            throw PlameExportException::valorFueraDeRango($campo, $texto, "máximo {$maxDigitosEnteros} dígitos enteros — Anexo 3, E20");
        }

        if ($valor <= 0) {
            throw PlameExportException::campoRequeridoFaltante($campo);
        }

        return $texto;
    }

    /**
     * Campo 11: obligatorio solo si el indicador es "1" (ONP) o "2" (SPP);
     * si es "3" (sin retención/no aplica) debe quedar VACÍO — nunca "0.00"
     * (Anexo 3, observación del campo 11).
     */
    private function importeAporte(BoletaComprobanteRh $comprobante, string $indicadorPensionario, int $colaboradorId): string
    {
        if ($indicadorPensionario === '3') {
            if ($comprobante->importe_aporte_regimen_pensionario !== null) {
                // Dato inconsistente: indica "sin retención" pero igual
                // registró un importe — no se descarta en silencio.
                throw PlameExportException::formatoInvalido("importe_aporte_regimen_pensionario (colaborador_id={$colaboradorId})", (string) $comprobante->importe_aporte_regimen_pensionario);
            }

            return '';
        }

        if ($comprobante->importe_aporte_regimen_pensionario === null) {
            throw PlameExportException::campoRequeridoFaltante('importe_aporte_regimen_pensionario', $colaboradorId);
        }

        $valor = $comprobante->importe_aporte_regimen_pensionario;
        $parteEntera = explode('.', ltrim($valor, '-'))[0];
        if (strlen($parteEntera) > self::MAX_DIGITOS_ENTEROS_APORTE) {
            throw PlameExportException::valorFueraDeRango("importe_aporte_regimen_pensionario (colaborador_id={$colaboradorId})", $valor, 'máximo 7 dígitos enteros — Anexo 3, E20');
        }

        return $valor;
    }
}
