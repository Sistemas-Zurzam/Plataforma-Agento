<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Str;

/**
 * El PDF no exige un nombre de archivo rígido (a diferencia de PLAME) —
 * se usa una convención amigable Agento, NUNCA declarada como nombre
 * oficial BCP (Sección 32 del encargo).
 */
final class TelecreditoBcpFilenameBuilder
{
    public static function construir(Empresa $empresa, CicloRemunerativo $ciclo): string
    {
        $periodo = $ciclo->fecha_inicio->format('Y_m');

        return 'TELECREDITO_BCP_'.Str::upper(Str::slug($empresa->nombre_comercial, '_'))."_{$periodo}.txt";
    }
}
