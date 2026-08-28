<?php

namespace App\Modules\Nominas\Domain\AfpNet;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;

/**
 * Nombre de archivo AFPnet — NO se encontró un patrón de nombre rígido
 * exigido por AFPnet ni en el macro ni en ningún archivo de guía
 * disponible en el sistema (Sección 29 del encargo: "no inventar un
 * patrón oficial si no existe"). Se usa un nombre amigable Agento; si en
 * el futuro se confirma un naming oficial distinto, este es el único
 * lugar que hay que tocar.
 */
final class AfpNetFilenameBuilder
{
    public static function construir(Empresa $empresa, CicloRemunerativo $ciclo, string $extension): string
    {
        $ruc = $empresa->ruc ?? 'SINRUC';
        $periodo = $ciclo->fecha_inicio->format('Ym');

        return "AFPnet_{$ruc}_{$periodo}.{$extension}";
    }
}
