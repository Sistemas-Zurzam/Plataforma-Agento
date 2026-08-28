<?php

namespace App\Modules\Nominas\Application\Plame;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Collection;

/**
 * Único lugar que sabe CÓMO cargar las boletas/condiciones de un ciclo para
 * PLAME — compartido por PlameValidator y PlameExportService (Sección 68:
 * ambos deben leer EXACTAMENTE los mismos datos, con los mismos filtros y
 * eager loads; si divergieran, un ciclo podría validarse "listo" contra un
 * conjunto de boletas distinto al que realmente se exporta).
 */
final class PlameCicloDatosLoader
{
    public static function boletasPlanilla(CicloRemunerativo $ciclo): Collection
    {
        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')
            ->with(['colaborador', 'conceptos.concepto'])
            ->get();
    }

    public static function boletasRh(CicloRemunerativo $ciclo): Collection
    {
        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '=', 'Locacion de Servicios')
            ->with(['colaborador', 'conceptos.concepto', 'comprobanteRh'])
            ->get();
    }

    /**
     * @return Collection<int, Collection<int, ColaboradorCondicionLaboral>>
     */
    public static function condicionesPorColaborador(Collection $colaboradorIds): Collection
    {
        return ColaboradorCondicionLaboral::whereIn('colaborador_id', $colaboradorIds)
            ->orderByDesc('vigencia_desde')->orderByDesc('id')
            ->get()
            ->groupBy('colaborador_id');
    }
}
