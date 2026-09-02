<?php

namespace App\Modules\Nominas\Application\TelecreditoBcp;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpExportException;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpExportResultado;
use App\Modules\Nominas\Domain\TelecreditoBcp\TelecreditoBcpFilenameBuilder;
use App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export\TelecreditoBcpTxtExporter;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Carbon;

/**
 * Orquestador Telecrédito BCP (Sección 33 del encargo del exportador):
 * ciclo autorizado → verifica estado → TelecreditoBcpValidator (gate) →
 * arma referencias → llama al Exporter → devuelve archivo. Nunca
 * recalcula nómina (Sección 2/43): solo lee boletas/snapshot bancario ya
 * existentes.
 *
 * Regla del ciclo (Sección 41): exige CERRADO como mínimo, igual que el
 * Validator. Un ciclo PAGADO también puede generarse (regeneración de
 * consulta histórica, con advertencia) — NUNCA cambia
 * boleta.estado/ciclo.estado/referencia_pago (Sección 42): descargar el
 * TXT no significa que BCP pagó.
 */
class TelecreditoBcpExportService
{
    public function __construct(private readonly TelecreditoBcpValidator $validator) {}

    /** @param array<int, int> $boletaIds */
    public function exportar(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $fechaProceso, string $subtipo, array $boletaIds = []): TelecreditoBcpExportResultado
    {
        $validacion = $this->validator->validar($ciclo, $cuentaCargo, $fechaProceso, $subtipo, $boletaIds);

        if (! in_array($ciclo->estado, ['cerrado', 'pagado'], true)) {
            return TelecreditoBcpExportResultado::bloqueado(
                'TELECREDITO_CICLO_NO_CERRADO',
                'El ciclo debe estar "cerrado" (snapshot definitivo) para generar el archivo Telecrédito.',
                $validacion,
            );
        }

        if (! $validacion['listo']) {
            return TelecreditoBcpExportResultado::bloqueado(
                'TELECREDITO_HALLAZGOS_BLOQUEANTES',
                'No se puede generar el archivo Telecrédito: existen hallazgos bloqueantes.',
                $validacion,
            );
        }

        if ($validacion['abonos'] === 0) {
            return TelecreditoBcpExportResultado::bloqueado(
                'TELECREDITO_SIN_ABONOS',
                'No existen trabajadores con neto a pagar en este período.',
                $validacion,
            );
        }

        $boletas = TelecreditoBcpCicloDatosLoader::poblacion($ciclo, $subtipo, $boletaIds);

        $referenciaPlanilla = $this->construirReferenciaPlanilla($ciclo);

        try {
            $contenido = TelecreditoBcpTxtExporter::generar(
                $cuentaCargo,
                Carbon::parse($fechaProceso)->format('Ymd'),
                $subtipo,
                $referenciaPlanilla,
                $boletas,
            );
        } catch (TelecreditoBcpExportException $e) {
            // Resguardo final (Sección 29): el Validator ya debió bloquear
            // esto — si de todas formas ocurre, es un error de negocio
            // estructurado, nunca un 500 genérico ni un archivo parcial.
            return TelecreditoBcpExportResultado::bloqueado('TELECREDITO_ERROR_GENERACION', $e->getMessage(), $validacion);
        }

        $nombre = TelecreditoBcpFilenameBuilder::construir($ciclo->empresa, $ciclo);

        return TelecreditoBcpExportResultado::generado(
            'Archivo Telecrédito BCP generado correctamente.',
            ['nombre' => $nombre, 'contenido' => $contenido],
            $validacion,
        );
    }

    /**
     * CORREGIDO: el regex "solo letras y espacios" que imprime el PDF
     * ("^[a-zA-ZáéíóúÁÉÍÓÚñÑýÝ -.()#/@&]*$") es falso en la práctica —
     * confirmado leyendo byte a byte la cabecera de un archivo histórico
     * real aceptado por BCP: la referencia de planilla es literalmente
     * "Planilla Julio 2026" (con dígitos de año). Ante el conflicto entre
     * el PDF y el archivo realmente aceptado por el banco, prevalece el
     * archivo (mismo criterio que el resto del formato — ver
     * TelecreditoBcpHeaderBuilder). Las referencias de beneficiario/
     * empresa (por colaborador, no de lote) se corrigieron aparte, en
     * TelecreditoBcpPagoBuilder.
     */
    private function construirReferenciaPlanilla(CicloRemunerativo $ciclo): string
    {
        $mes = ucfirst(mb_strtolower($ciclo->fecha_inicio->translatedFormat('F'), 'UTF-8'));
        $anio = $ciclo->fecha_inicio->format('Y');

        return "Planilla {$mes} {$anio}";
    }
}
