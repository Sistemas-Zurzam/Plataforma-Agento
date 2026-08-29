<?php

namespace App\Modules\Nominas\Application\TelecreditoBcp;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;

/**
 * Única fuente de la población Telecrédito — compartida por
 * TelecreditoBcpValidator y TelecreditoBcpExportService (Sección 44 del
 * encargo: determinismo — ambos deben leer EXACTAMENTE la misma
 * colección, en el mismo orden). Neto distinto de cero (Sección
 * 15/27/34) — neto=0 se excluye en silencio (no produce línea bancaria,
 * no es error); neto negativo SÍ queda incluido acá para que el
 * Validator lo reporte como bloqueante.
 *
 * Corrección: el subtipo '4' (Cuarta Categoría) y el resto de subtipos
 * (incluido 'X', Quinta Categoría) apuntan a poblaciones DISTINTAS —
 * antes el filtro de régimen quedaba hardcodeado a "!= Locacion de
 * Servicios" sin importar el subtipo elegido, así que seleccionar '4' en
 * el modal seguía trayendo dependientes (5ta) en vez de locadores (4ta).
 */
final class TelecreditoBcpCicloDatosLoader
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
