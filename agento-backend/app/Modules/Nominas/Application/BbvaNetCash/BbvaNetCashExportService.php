<?php

namespace App\Modules\Nominas\Application\BbvaNetCash;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportException;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportResultado;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashFilenameBuilder;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtExporter;
use App\Modules\Nominas\Models\CicloRemunerativo;

/**
 * Orquestador BBVA Net Cash: ciclo autorizado → verifica estado →
 * BbvaNetCashValidator (gate) → arma referencia → llama al Exporter →
 * devuelve archivo. Nunca recalcula nómina: solo lee boletas/snapshot
 * bancario ya existentes. Completamente independiente de
 * TelecreditoBcpExportService — no comparte código con Telecrédito.
 *
 * Regla del ciclo: exige CERRADO como mínimo, igual que el Validator. Un
 * ciclo PAGADO también puede generarse (regeneración de consulta
 * histórica) — NUNCA cambia boleta.estado/ciclo.estado: descargar el TXT
 * no significa que BBVA ya pagó.
 *
 * Sin fecha de proceso: confirmado contra un archivo real generado por el
 * macro (BBVAH4Cat.txt) que "Tipo de proceso" = "A" no exige fecha ni hora
 * — Agento usa siempre ese valor (BbvaNetCashFormato::TIPO_PROCESO), así
 * que ese campo de cabecera queda en blanco y no hace falta resolverlo
 * desde el ciclo.
 */
class BbvaNetCashExportService
{
    public function __construct(private readonly BbvaNetCashValidator $validator) {}

    /** @param array<int, int> $boletaIds */
    public function exportar(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $subtipo, array $boletaIds = []): BbvaNetCashExportResultado
    {
        $validacion = $this->validator->validar($ciclo, $cuentaCargo, $subtipo, $boletaIds);

        if (! in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            return BbvaNetCashExportResultado::bloqueado(
                'BBVA_CICLO_NO_CERRADO',
                'El ciclo debe estar "cerrado" (snapshot definitivo) para generar el archivo BBVA Net Cash.',
                $validacion,
            );
        }

        if (! $validacion['listo']) {
            return BbvaNetCashExportResultado::bloqueado(
                'BBVA_HALLAZGOS_BLOQUEANTES',
                'No se puede generar el archivo BBVA Net Cash: existen hallazgos bloqueantes.',
                $validacion,
            );
        }

        if ($validacion['abonos'] === 0) {
            return BbvaNetCashExportResultado::bloqueado(
                'BBVA_SIN_ABONOS',
                'No existen trabajadores con neto a pagar en este período para el subtipo seleccionado.',
                $validacion,
            );
        }

        $boletas = BbvaNetCashCicloDatosLoader::poblacion($ciclo, $subtipo, $boletaIds);

        $referencia = $this->construirReferencia($ciclo, $subtipo);

        try {
            $contenido = BbvaNetCashTxtExporter::generar(
                $cuentaCargo,
                $subtipo,
                $referencia,
                $boletas,
            );
        } catch (BbvaNetCashExportException $e) {
            // Resguardo final: el Validator ya debió bloquear esto — si de
            // todas formas ocurre, es un error de negocio estructurado,
            // nunca un 500 genérico ni un archivo parcial.
            return BbvaNetCashExportResultado::bloqueado('BBVA_ERROR_GENERACION', $e->getMessage(), $validacion);
        }

        $nombre = BbvaNetCashFilenameBuilder::construir($ciclo->empresa, $ciclo, $subtipo);

        return BbvaNetCashExportResultado::generado(
            'Archivo BBVA Net Cash generado correctamente.',
            ['nombre' => $nombre, 'contenido' => $contenido],
            $validacion,
        );
    }

    /**
     * Referencia determinística de 25 caracteres útiles (el campo mide 25
     * en cabecera y 40 en detalle — se arma la más corta y se reutiliza en
     * ambos, izquierda() la recorta si hiciera falta).
     */
    private function construirReferencia(CicloRemunerativo $ciclo, string $subtipo): string
    {
        $mes = mb_strtoupper($ciclo->fecha_inicio->translatedFormat('F'), 'UTF-8');
        $etiquetaSubtipo = $subtipo === '4' ? 'RH' : 'HABERES';

        return "{$etiquetaSubtipo} {$mes}";
    }
}
