<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Personas\Models\Colaborador;

/**
 * Representación estructurada COMÚN de los 17 campos AFPnet (Sección 30
 * del encargo) — un solo lugar decide los VALORES de negocio de cada fila;
 * AfpNetExcelExporter y AfpNetTxtExporter solo los serializan cada uno a
 * su propio formato técnico (celda de texto vs. posición de ancho fijo).
 * Ninguna regla de negocio se repite en los Exporters.
 *
 * Devuelve valores de negocio SIN formatear (strings/números simples) —
 * el padding/truncamiento/9(n).9(n) es responsabilidad de cada Exporter,
 * nunca de este builder.
 */
final class AfpNetFilaBuilder
{
    private const TIPO_TRABAJO_DEPENDIENTE_NORMAL = 'N';

    private const RELACION_LABORAL_VIGENTE = 'S';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function construir(AfpNetExportContext $contexto): array
    {
        $ordenadas = $contexto->boletasAfp->sortBy([
            fn (Boleta $a, Boleta $b) => $a->colaborador->tipo_documento <=> $b->colaborador->tipo_documento,
            fn (Boleta $a, Boleta $b) => $a->colaborador->numero_documento <=> $b->colaborador->numero_documento,
        ])->values();

        $filas = [];
        foreach ($ordenadas as $indice => $boleta) {
            $filas[] = self::fila($contexto, $boleta, $indice + 1);
        }

        return $filas;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fila(AfpNetExportContext $contexto, Boleta $boleta, int $secuencia): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw AfpNetExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        $tipoDocumento = $contexto->mapeos->codigoDocumento($colaborador->tipo_documento);
        if (blank($tipoDocumento)) {
            throw AfpNetExportException::campoRequeridoFaltante("mapeo AFPnet de tipo_documento \"{$colaborador->tipo_documento}\"", $colaborador->id);
        }

        if (blank($colaborador->apellido_paterno) || blank($colaborador->numero_documento) || blank($colaborador->nombres)) {
            throw AfpNetExportException::campoRequeridoFaltante('apellido_paterno/numero_documento/nombres', $colaborador->id);
        }

        if (filled($colaborador->cuspp) && mb_strlen($colaborador->cuspp) !== 12) {
            throw AfpNetExportException::formatoInvalido('cuspp', $colaborador->cuspp, $colaborador->id);
        }

        $condicion = $contexto->condicionVigente($colaborador->id);
        $claveAfp = $condicion?->sistema_previsional ?? $colaborador->sistema_previsional;
        $codigoAfp = $claveAfp ? $contexto->mapeos->codigoAfp($claveAfp) : null;
        if ($claveAfp && blank($codigoAfp)) {
            throw AfpNetExportException::campoRequeridoFaltante("mapeo AFPnet de la AFP \"{$claveAfp}\"", $colaborador->id);
        }

        $lineaAfp = $boleta->conceptos->first(fn ($c) => $c->concepto?->codigo === 'AFP_APORTE_OBLIGATORIO');
        if (! $lineaAfp || $lineaAfp->base_utilizada === null) {
            throw AfpNetExportException::campoRequeridoFaltante('remuneración asegurable (línea AFP_APORTE_OBLIGATORIO)', $colaborador->id);
        }
        $remuneracionAsegurable = (string) $lineaAfp->base_utilizada;

        $excepcion = ResolverExcepcionAfpNet::resolver($colaborador, $contexto->ciclo, (float) $remuneracionAsegurable, $contexto->permisosDe($colaborador->id));
        if (! $excepcion['determinado']) {
            // AfpNetValidator ya debió bloquear esto antes de exportar
            // (Sección 4 del encargo) — resguardo final, nunca se adivina.
            throw AfpNetExportException::campoRequeridoFaltante("excepción de aportar ({$excepcion['motivo']})", $colaborador->id);
        }

        $inicioEnPeriodo = $colaborador->fecha_ingreso
            && $colaborador->fecha_ingreso->toDateString() >= $contexto->ciclo->fecha_inicio->toDateString()
            && $colaborador->fecha_ingreso->toDateString() <= $contexto->ciclo->fecha_fin->toDateString();

        $ceseEnPeriodo = $colaborador->fecha_cese
            && $colaborador->fecha_cese->toDateString() >= $contexto->ciclo->fecha_inicio->toDateString()
            && $colaborador->fecha_cese->toDateString() <= $contexto->ciclo->fecha_fin->toDateString();

        return [
            'secuencia' => $secuencia,
            'cuspp' => $colaborador->cuspp,
            'tipo_documento' => $tipoDocumento,
            'numero_documento' => $colaborador->numero_documento,
            'apellido_paterno' => $colaborador->apellido_paterno,
            'apellido_materno' => $colaborador->apellido_materno ?? '',
            'nombres' => $colaborador->nombres,
            'relacion_laboral' => self::RELACION_LABORAL_VIGENTE,
            'inicio_relacion_laboral' => $inicioEnPeriodo ? 'S' : 'N',
            'cese_relacion_laboral' => $ceseEnPeriodo ? 'S' : 'N',
            'excepcion_aportar' => $excepcion['codigo'],
            'remuneracion_asegurable' => $remuneracionAsegurable,
            'aporte_voluntario_con_fin' => AfpNetAportesVoluntarios::conFinPrevisional(),
            'aporte_voluntario_sin_fin' => AfpNetAportesVoluntarios::sinFinPrevisional(),
            'aporte_voluntario_empleador' => AfpNetAportesVoluntarios::delEmpleador(),
            'tipo_trabajo' => self::TIPO_TRABAJO_DEPENDIENTE_NORMAL,
            'afp' => $codigoAfp,
        ];
    }
}
