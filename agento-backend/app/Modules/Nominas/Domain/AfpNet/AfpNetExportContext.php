<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Collection;

/**
 * Datos YA cargados de un ciclo para AFPnet — un solo contexto que
 * AfpNetValidator, AfpNetExcelExporter y AfpNetTxtExporter comparten
 * (Sección 7 del encargo: evitar que cada uno dispare sus propias
 * queries). Completamente separado de PlameExportContext — nunca se
 * importa nada de Domain\Plame.
 */
final class AfpNetExportContext
{
    /**
     * @param  Collection<int, \App\Modules\Nominas\Models\Boleta>  $boletasAfp  Ya filtradas a la población SPP elegible (Sección 6/27).
     * @param  Collection<int, Collection<int, ColaboradorCondicionLaboral>>  $condicionesPorColaborador  Agrupado por colaborador_id, orden desc por vigencia_desde.
     * @param  Collection<int, Collection<int, \App\Modules\Asistencia\Models\AsistenciaPermiso>>  $permisosPorColaborador  Agrupado por colaborador_id.
     */
    public function __construct(
        public readonly Empresa $empresa,
        public readonly CicloRemunerativo $ciclo,
        public readonly Collection $boletasAfp,
        public readonly Collection $condicionesPorColaborador,
        public readonly Collection $permisosPorColaborador,
        public readonly AfpNetMapeoLookup $mapeos,
    ) {}

    /**
     * Condición previsional/contractual vigente en fecha_fin del ciclo —
     * NUNCA la condición actual del colaborador (Sección 7/28: histórico).
     */
    public function condicionVigente(int $colaboradorId): ?ColaboradorCondicionLaboral
    {
        $fecha = $this->ciclo->fecha_fin->toDateString();

        return $this->condicionesPorColaborador->get($colaboradorId, collect())
            ->first(fn (ColaboradorCondicionLaboral $c) => $c->vigencia_desde->toDateString() <= $fecha);
    }

    public function permisosDe(int $colaboradorId): Collection
    {
        return $this->permisosPorColaborador->get($colaboradorId, collect());
    }
}
