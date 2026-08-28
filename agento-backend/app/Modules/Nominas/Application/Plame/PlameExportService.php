<?php

namespace App\Modules\Nominas\Application\Plame;

use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Nominas\Domain\Plame\PlameExportResultado;
use App\Modules\Nominas\Domain\Plame\PlameFilenameBuilder;
use App\Modules\Nominas\Domain\Plame\SunatMapeoLookup;
use App\Modules\Nominas\Infrastructure\Plame\Export\CuartaGenerator;
use App\Modules\Nominas\Infrastructure\Plame\Export\JorGenerator;
use App\Modules\Nominas\Infrastructure\Plame\Export\PlameTxtSerializer;
use App\Modules\Nominas\Infrastructure\Plame\Export\Ps4Generator;
use App\Modules\Nominas\Infrastructure\Plame\Export\RemGenerator;
use App\Modules\Nominas\Infrastructure\Plame\Export\SnlGenerator;
use App\Modules\Nominas\Models\CicloRemunerativo;

/**
 * Orquestador de exportación PLAME (Sección 43): valida → determina
 * archivos aplicables → llama a los Generators → arma el resultado. NUNCA
 * implementa una regla de una estructura específica acá adentro (Sección
 * 43) — cada Generator sabe construir SU archivo, este servicio solo
 * decide CUÁLES corresponde generar.
 *
 * Regla de negocio central (Sección 5/6/63): la descarga PLAME DEFINITIVA
 * solo procede sobre un ciclo "pagado" (snapshot inmutable) — un ciclo en
 * cualquier otro estado puede VALIDARSE (PlameValidator no tiene esta
 * restricción) pero no EXPORTARSE en definitiva. Nunca recalcula nada: solo
 * lee boletas/conceptos ya existentes.
 */
class PlameExportService
{
    private const ARCHIVOS_PLANILLA = ['jor', 'snl', 'rem'];

    private const ARCHIVOS_RH = ['ps4', 'cuarta'];

    public function __construct(
        private readonly PlameValidator $validator,
        private readonly JorGenerator $jorGenerator,
        private readonly SnlGenerator $snlGenerator,
        private readonly RemGenerator $remGenerator,
        private readonly Ps4Generator $ps4Generator,
        private readonly CuartaGenerator $cuartaGenerator,
    ) {}

    public function exportarPlanilla(CicloRemunerativo $ciclo): PlameExportResultado
    {
        return $this->exportar($ciclo, self::ARCHIVOS_PLANILLA, 'Planilla (.jor/.snl/.rem)');
    }

    public function exportarRh(CicloRemunerativo $ciclo): PlameExportResultado
    {
        return $this->exportar($ciclo, self::ARCHIVOS_RH, 'RH (.ps4/.4ta)');
    }

    public function exportarCompleto(CicloRemunerativo $ciclo): PlameExportResultado
    {
        return $this->exportar($ciclo, [...self::ARCHIVOS_PLANILLA, ...self::ARCHIVOS_RH], 'completa (Planilla + RH)');
    }

    /**
     * @param  array<int, string>  $archivosSolicitados
     */
    private function exportar(CicloRemunerativo $ciclo, array $archivosSolicitados, string $etiqueta): PlameExportResultado
    {
        $validacion = $this->validator->validar($ciclo);

        // Regla del ciclo definitivo (Sección 5/6/63) — la validación
        // preliminar puede correr sobre cualquier estado, pero la descarga
        // definitiva NUNCA. No cambia el estado del ciclo automáticamente
        // (Sección 5): solo lo lee.
        if ($ciclo->estado !== 'pagado') {
            return PlameExportResultado::bloqueado(
                'PLAME_CICLO_NO_PAGADO',
                'El ciclo debe estar en estado "pagado" para generar los archivos definitivos PLAME.',
                $validacion,
            );
        }

        $bloqueantes = collect($archivosSolicitados)
            ->filter(fn (string $archivo) => ($validacion['archivos'][$archivo]['estado'] ?? 'no_aplica') === 'bloqueado')
            ->values();

        if ($bloqueantes->isNotEmpty()) {
            // Sección 4: nunca generar silenciosamente un paquete
            // incompleto — si UNO de los archivos aplicables del grupo
            // solicitado está bloqueado, se bloquea el grupo completo.
            return PlameExportResultado::bloqueado(
                'PLAME_ARCHIVOS_BLOQUEADOS',
                "No se puede generar la exportación {$etiqueta}: ".$bloqueantes->implode(', ').' tiene(n) hallazgos bloqueantes.',
                $validacion,
            );
        }

        // Sección 42 (decisión documentada): un archivo con 0 registros
        // aplicables (estado "no_aplica") NO se genera — no se produce un
        // .txt vacío. Se omite en silencio de la lista de archivos, nunca
        // como error.
        $archivosAplicables = collect($archivosSolicitados)
            ->filter(fn (string $archivo) => ($validacion['archivos'][$archivo]['estado'] ?? 'no_aplica') !== 'no_aplica')
            ->values();

        if ($archivosAplicables->isEmpty()) {
            return PlameExportResultado::generado(
                "No existen registros aplicables para la exportación {$etiqueta} en este período.",
                [],
                $validacion,
            );
        }

        $contexto = $this->construirContexto($ciclo);

        try {
            $archivos = $archivosAplicables
                ->map(fn (string $archivo) => $this->generarArchivo($archivo, $contexto))
                ->all();
        } catch (PlameExportException $e) {
            // Resguardo final (Sección 21/72): PlameValidator ya debió
            // haber bloqueado esto — si de todas formas ocurre, es un
            // error de negocio estructurado, nunca un 500 genérico.
            return PlameExportResultado::bloqueado('PLAME_ERROR_GENERACION', $e->getMessage(), $validacion);
        }

        return PlameExportResultado::generado("Exportación {$etiqueta} generada correctamente.", $archivos, $validacion);
    }

    /**
     * @return array{nombre: string, contenido: string}
     */
    private function generarArchivo(string $archivo, PlameExportContext $contexto): array
    {
        [$extension, $filas] = match ($archivo) {
            'jor' => ['jor', $this->jorGenerator->generar($contexto)],
            'snl' => ['snl', $this->snlGenerator->generar($contexto)],
            'rem' => ['rem', $this->remGenerator->generar($contexto)],
            'ps4' => ['ps4', $this->ps4Generator->generar($contexto)],
            'cuarta' => ['4ta', $this->cuartaGenerator->generar($contexto)],
        };

        return [
            'nombre' => PlameFilenameBuilder::construir($contexto->empresa, $contexto->ciclo, $extension),
            'contenido' => PlameTxtSerializer::serializar($filas),
        ];
    }

    private function construirContexto(CicloRemunerativo $ciclo): PlameExportContext
    {
        return new PlameExportContext(
            $ciclo->empresa,
            $ciclo,
            PlameCicloDatosLoader::boletasPlanilla($ciclo),
            PlameCicloDatosLoader::boletasRh($ciclo),
            SunatMapeoLookup::cargar(),
        );
    }
}
