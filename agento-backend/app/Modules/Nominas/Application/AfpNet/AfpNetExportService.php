<?php

namespace App\Modules\Nominas\Application\AfpNet;

use App\Modules\Nominas\Domain\AfpNet\AfpNetExportException;
use App\Modules\Nominas\Domain\AfpNet\AfpNetExportResultado;
use App\Modules\Nominas\Domain\AfpNet\AfpNetFilaBuilder;
use App\Modules\Nominas\Domain\AfpNet\AfpNetFilenameBuilder;
use App\Modules\Nominas\Infrastructure\AfpNet\Export\AfpNetExcelExporter;
use App\Modules\Nominas\Infrastructure\AfpNet\Export\AfpNetTxtExporter;
use App\Modules\Nominas\Models\CicloRemunerativo;

/**
 * Orquestador de exportación AFPnet (Sección 31 del encargo): recibe
 * ciclo autorizado → verifica estado → carga contexto → ejecuta
 * AfpNetValidator (gate) → construye filas → llama al Exporter
 * correspondiente → devuelve archivo. No implementa reglas de una
 * estructura específica acá (esas viven en AfpNetFilaBuilder/Exporters).
 *
 * Regla del ciclo definitivo (Sección 5, mismo principio que PLAME): la
 * descarga AFPnet DEFINITIVA solo procede sobre un ciclo "pagado" —
 * AfpNetValidator puede correr sobre cualquier estado, la exportación no.
 * Nunca recalcula: solo lee boletas/conceptos ya existentes.
 */
class AfpNetExportService
{
    public function __construct(private readonly AfpNetValidator $validator) {}

    public function exportarExcel(CicloRemunerativo $ciclo): AfpNetExportResultado
    {
        return $this->exportar($ciclo, 'xlsx', fn (array $filas) => AfpNetExcelExporter::generar($filas), 'Excel');
    }

    public function exportarTxt(CicloRemunerativo $ciclo): AfpNetExportResultado
    {
        return $this->exportar($ciclo, 'txt', fn (array $filas) => AfpNetTxtExporter::generar($filas), 'TXT');
    }

    private function exportar(CicloRemunerativo $ciclo, string $extension, callable $serializar, string $etiqueta): AfpNetExportResultado
    {
        $validacion = $this->validator->validar($ciclo);

        if ($ciclo->estado !== 'pagado') {
            return AfpNetExportResultado::bloqueado(
                'AFPNET_CICLO_NO_PAGADO',
                'El ciclo debe estar en estado "pagado" para generar el archivo definitivo AFPnet.',
                $validacion,
            );
        }

        if (! $validacion['listo']) {
            return AfpNetExportResultado::bloqueado(
                'AFPNET_HALLAZGOS_BLOQUEANTES',
                "No se puede generar la exportación AFPnet {$etiqueta}: existen hallazgos bloqueantes.",
                $validacion,
            );
        }

        if (($validacion['resumen']['trabajadores'] ?? 0) === 0) {
            // Sección 40: nunca un error — es información, el frontend
            // deshabilita el botón con este mensaje.
            return AfpNetExportResultado::sinTrabajadores(
                'No existen trabajadores afiliados al SPP en este período.',
                $validacion,
            );
        }

        $contexto = AfpNetCicloDatosLoader::cargar($ciclo);

        try {
            $filas = AfpNetFilaBuilder::construir($contexto);
            $contenido = $serializar($filas);
        } catch (AfpNetExportException $e) {
            // Resguardo final: AfpNetValidator ya debió bloquear esto —
            // si de todas formas ocurre, es un error de negocio
            // estructurado, nunca un 500 genérico.
            return AfpNetExportResultado::bloqueado('AFPNET_ERROR_GENERACION', $e->getMessage(), $validacion);
        }

        $nombre = AfpNetFilenameBuilder::construir($contexto->empresa, $ciclo, $extension);

        return AfpNetExportResultado::generado(
            "Exportación AFPnet {$etiqueta} generada correctamente.",
            ['nombre' => $nombre, 'contenido' => $contenido],
            $validacion,
        );
    }
}
