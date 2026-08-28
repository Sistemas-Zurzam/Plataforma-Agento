<?php

namespace App\Modules\Nominas\Application\TelecreditoBcp;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;

/**
 * Única fuente de la población Telecrédito — compartida por
 * TelecreditoBcpValidator y TelecreditoBcpExportService (Sección 44 del
 * encargo: determinismo — ambos deben leer EXACTAMENTE la misma
 * colección, en el mismo orden). Primera fase: solo dependientes con
 * neto distinto de cero (Sección 15/27/34) — neto=0 se excluye en
 * silencio (no produce línea bancaria, no es error); neto negativo SÍ
 * queda incluido acá para que el Validator lo reporte como bloqueante.
 */
final class TelecreditoBcpCicloDatosLoader
{
    public static function poblacion(CicloRemunerativo $ciclo): Collection
    {
        return Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            ->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')
            ->where('neto_a_pagar', '!=', 0)
            ->with(['colaborador', 'datosPago.banco'])
            ->orderBy('colaborador_id')
            ->get();
    }
}
