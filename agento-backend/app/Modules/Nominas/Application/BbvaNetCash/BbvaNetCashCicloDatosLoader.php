<?php

namespace App\Modules\Nominas\Application\BbvaNetCash;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;

/**
 * Única fuente de la población BBVA Net Cash — compartida por
 * BbvaNetCashValidator y BbvaNetCashExportService (preview, validación y
 * exportación deben leer EXACTAMENTE la misma colección, en el mismo
 * orden). Mismo criterio y misma condición que
 * TelecreditoBcpCicloDatosLoader::poblacion() — deliberadamente duplicada
 * en vez de compartida entre integraciones (cada banco encapsula su
 * propia resolución de población, aunque hoy coincida).
 *
 * subtipo '5' = Planilla (dependientes), subtipo '4' = RH/Locación de
 * Servicios — nunca la condición laboral viva del colaborador si el ciclo
 * ya está congelado, siempre `regimen_laboral_snapshot` de la boleta.
 */
final class BbvaNetCashCicloDatosLoader
{
    public static function poblacion(CicloRemunerativo $ciclo, string $subtipo): Collection
    {
        $esCuartaCategoria = $subtipo === '4';

        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', $esCuartaCategoria ? '=' : '!=', 'Locacion de Servicios')
            ->where('neto_a_pagar', '!=', 0)
            ->with(['colaborador', 'datosPago.banco'])
            ->orderBy('colaborador_id')
            ->get();
    }
}
