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

    public function exportar(CicloRemunerativo $ciclo, EmpresaCuentaBancaria $cuentaCargo, string $fechaProceso, string $subtipo): TelecreditoBcpExportResultado
    {
        $validacion = $this->validator->validar($ciclo, $cuentaCargo, $fechaProceso, $subtipo);

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

        $boletas = TelecreditoBcpCicloDatosLoader::poblacion($ciclo);

        [$referenciaPlanilla, $referenciaBeneficiario, $referenciaEmpresa] = $this->construirReferencias($ciclo);

        try {
            $contenido = TelecreditoBcpTxtExporter::generar(
                $cuentaCargo,
                Carbon::parse($fechaProceso)->format('Ymd'),
                $subtipo,
                $referenciaPlanilla,
                $referenciaBeneficiario,
                $referenciaEmpresa,
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
     * Referencias determinísticas (Sección 14/24/25) — SOLO letras y
     * espacios: el regex de caracteres permitidos que imprime el PDF
     * ("^[a-zA-ZáéíóúÁÉÍÓÚñÑýÝ -.()#/@&]*$") no incluye dígitos, así que
     * NO se genera "AGOSTO 2026" ni "CICLO 123" hasta confirmar si BCP
     * realmente los rechaza. Limitación documentada (Sección 25): sin
     * dígitos disponibles, la referencia de empresa no es única entre
     * ciclos del mismo mes en años distintos — aceptable para esta
     * primera versión, a revisar cuando se homologue el regex real.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function construirReferencias(CicloRemunerativo $ciclo): array
    {
        $mes = mb_strtoupper($ciclo->fecha_inicio->translatedFormat('F'), 'UTF-8');

        return [
            "PLANILLA HABERES {$mes}",
            "HABERES {$mes}",
            "CICLO {$mes}",
        ];
    }
}
