<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura E14 (.jor) — Trabajador: Datos de la Jornada Laboral.
 *
 * Campos exactos (Anexo 3, hoja "E14 - Trab.JorLab"), en este orden:
 *  1. Tipo de documento del trabajador   (Texto, Tabla 3)
 *  2. Número de documento del trabajador (Texto, máx 15)
 *  3. Horas ordinarias trabajadas        (Numérico, máx 3, tope 360)
 *  4. Minutos ordinarios trabajados      (Numérico, máx 2, tope 59)
 *  5. Horas en sobretiempo trabajadas    (Numérico, máx 3, tope 360)
 *  6. Minutos en sobretiempo trabajados  (Numérico, máx 2, tope 59)
 *
 * Fuente de horas: AsistenciaOperacionService::horasConsolidadasPorColaborador()
 * — la misma consolidación ya usada por PlameValidator (Sección 12), nunca
 * se vuelven a leer marcaciones ni se recalcula nada acá.
 */
final class JorGenerator
{
    private const MAX_HORAS = 360;

    private const MAX_MINUTOS = 59;

    public function __construct(private readonly AsistenciaOperacionService $asistencia) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function generar(PlameExportContext $contexto): array
    {
        $fechaInicio = $contexto->ciclo->fecha_inicio->toDateString();
        $fechaFin = $contexto->ciclo->fecha_fin->toDateString();

        return $contexto->boletasPlanilla
            // Orden determinístico (Sección 15): tipo_documento + numero_documento.
            ->sortBy([
                fn (Boleta $a, Boleta $b) => $a->colaborador->tipo_documento <=> $b->colaborador->tipo_documento,
                fn (Boleta $a, Boleta $b) => $a->colaborador->numero_documento <=> $b->colaborador->numero_documento,
            ])
            ->map(fn (Boleta $boleta) => $this->registro($contexto, $boleta, $fechaInicio, $fechaFin))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function registro(PlameExportContext $contexto, Boleta $boleta, string $fechaInicio, string $fechaFin): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw PlameExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        $tipoDocumento = $contexto->mapeos->codigo('tipo_documento', $colaborador->tipo_documento);
        $numeroDocumento = $colaborador->numero_documento;
        if (blank($numeroDocumento)) {
            throw PlameExportException::campoRequeridoFaltante('numero_documento', $colaborador->id);
        }

        $horas = $this->asistencia->horasConsolidadasPorColaborador($colaborador, $fechaInicio, $fechaFin);

        [$horasOrdinarias, $minutosOrdinarios] = $this->normalizar((int) $horas['minutos_ordinarios'], 'ordinarias', $colaborador->id);
        [$horasSobretiempo, $minutosSobretiempo] = $this->normalizar((int) $horas['minutos_extra_total'], 'sobretiempo', $colaborador->id);

        return [
            $tipoDocumento,
            $numeroDocumento,
            (string) $horasOrdinarias,
            (string) $minutosOrdinarios,
            (string) $horasSobretiempo,
            (string) $minutosSobretiempo,
        ];
    }

    /**
     * Normaliza un total de minutos a [horas, minutos] (Sección 13): 125
     * minutos → 2 horas, 5 minutos — nunca "0 horas, 125 minutos". Valida
     * los topes SUNAT (360h / 59min) sin truncar (Sección 14): si se
     * excede, es un error de datos real (asistencia sin procesar
     * correctamente), no algo que el exportador deba esconder con un min().
     *
     * @return array{0: int, 1: int}
     */
    private function normalizar(int $minutosTotales, string $etiqueta, int $colaboradorId): array
    {
        $minutosTotales = max(0, $minutosTotales);
        $horas = intdiv($minutosTotales, 60);
        $minutos = $minutosTotales % 60;

        if ($horas > self::MAX_HORAS) {
            throw PlameExportException::valorFueraDeRango("horas {$etiqueta} (colaborador_id={$colaboradorId})", $horas, 'máximo 360 horas — Anexo 3, E14');
        }

        if ($minutos > self::MAX_MINUTOS) {
            // Matemáticamente imposible tras el % 60, pero se deja el
            // resguardo explícito en vez de asumirlo silenciosamente.
            throw PlameExportException::valorFueraDeRango("minutos {$etiqueta} (colaborador_id={$colaboradorId})", $minutos, 'máximo 59 minutos — Anexo 3, E14');
        }

        return [$horas, $minutos];
    }
}
