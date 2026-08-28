<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use App\Modules\Nominas\Domain\Plame\ConceptosPlame;
use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura E18 (.rem) — Trabajador: Detalle de ingresos, tributos y
 * descuentos. El generador más sensible (Sección 22): consume ÚNICAMENTE
 * el snapshot ya calculado de boleta_conceptos, nunca recalcula nómina.
 *
 * Campos exactos (Anexo 3, hoja "E18-Trab.Rem"), en este orden:
 *  1. Tipo de documento del trabajador       (Texto, Tabla 3)
 *  2. Número de documento del trabajador     (Texto, máx 15)
 *  3. Código de concepto remunerativo/no remunerativo (Numérico, Tabla 22, 4 dígitos)
 *  4. Monto devengado                        (Numérico, "7,2")
 *  5. Monto pagado/descontado                (Numérico, "7,2")
 *
 * NO incluye nombre/cargo/empresa — esos campos no existen en E18 (Sección 23).
 */
final class RemGenerator
{
    private const MAX_DIGITOS_ENTEROS_MONTO = 7;

    /**
     * @return array<int, array<int, string>>
     */
    public function generar(PlameExportContext $contexto): array
    {
        $filas = [];

        $boletasOrdenadas = $contexto->boletasPlanilla->sortBy([
            fn (Boleta $a, Boleta $b) => $a->colaborador->tipo_documento <=> $b->colaborador->tipo_documento,
            fn (Boleta $a, Boleta $b) => $a->colaborador->numero_documento <=> $b->colaborador->numero_documento,
        ]);

        foreach ($boletasOrdenadas as $boleta) {
            /** @var Colaborador $colaborador */
            $colaborador = $boleta->colaborador;
            if (! $colaborador) {
                throw PlameExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
            }

            $tipoDocumento = $contexto->mapeos->codigo('tipo_documento', $colaborador->tipo_documento);

            // `conceptos()` no declara orderBy propio — se ordena acá por id
            // para que la regeneración del mismo ciclo pagado sea siempre
            // byte-a-byte idéntica (Sección 55), sin depender del orden
            // físico que MySQL devuelva sin ORDER BY explícito.
            foreach ($boleta->conceptos->sortBy('id') as $linea) {
                if (in_array($linea->concepto?->codigo, ConceptosPlame::NO_EXPORTABLES_REM, true)) {
                    // Provisión contable u honorario (E20, no E18) — Sección 28/29.
                    continue;
                }

                $filas[] = $this->registro($tipoDocumento, $colaborador, $linea);
            }
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    private function registro(string $tipoDocumento, Colaborador $colaborador, BoletaConcepto $linea): array
    {
        $codigoInterno = $linea->concepto?->codigo ?? '(sin concepto)';

        // Prioridad absoluta al snapshot histórico (Sección 24) — NUNCA
        // conceptos_remuneracion.codigo_plame actual.
        $codigo = $linea->codigo_plame_snapshot;
        if (blank($codigo)) {
            throw PlameExportException::campoRequeridoFaltante("codigo_plame_snapshot del concepto \"{$codigoInterno}\"", $colaborador->id);
        }

        if (! preg_match('/^\d{4}$/', $codigo)) {
            // Formato canónico Tabla 22 (Sección 25) — nunca se trunca ni
            // se convierte a entero, se rechaza explícitamente.
            throw PlameExportException::formatoInvalido("codigo_plame_snapshot del concepto \"{$codigoInterno}\"", $codigo);
        }

        if (in_array($codigo, ConceptosPlame::CODIGOS_EXCLUIDOS_REM, true)) {
            throw PlameExportException::valorFueraDeRango("codigo_plame_snapshot del concepto \"{$codigoInterno}\"", $codigo, 'código de encabezado/aportación administrada por SUNAT, no declarable por línea — Anexo 3, E18');
        }

        if ($linea->monto_devengado === null || $linea->monto_pagado_descontado === null) {
            throw PlameExportException::campoRequeridoFaltante("monto_devengado/monto_pagado_descontado del concepto \"{$codigoInterno}\"", $colaborador->id);
        }

        $devengado = $this->formatearMonto($linea->monto_devengado, "monto_devengado ({$codigoInterno})", $colaborador->id);
        $pagado = $this->formatearMonto($linea->monto_pagado_descontado, "monto_pagado_descontado ({$codigoInterno})", $colaborador->id);

        return [$tipoDocumento, $colaborador->numero_documento, $codigo, $devengado, $pagado];
    }

    /**
     * El cast `decimal:2` de Eloquent ya entrega un string de precisión
     * exacta (BigDecimal, sin FLOAT — Sección 27) con punto decimal y 2
     * decimales, ej. "1234.00" — nunca se recalcula, solo se valida el
     * tope de dígitos enteros y se retorna tal cual.
     */
    private function formatearMonto(string $valor, string $campo, int $colaboradorId): string
    {
        $parteEntera = explode('.', ltrim($valor, '-'))[0];

        if (strlen($parteEntera) > self::MAX_DIGITOS_ENTEROS_MONTO) {
            throw PlameExportException::valorFueraDeRango("{$campo} (colaborador_id={$colaboradorId})", $valor, 'máximo 7 dígitos enteros — Anexo 3, E18');
        }

        return $valor;
    }
}
