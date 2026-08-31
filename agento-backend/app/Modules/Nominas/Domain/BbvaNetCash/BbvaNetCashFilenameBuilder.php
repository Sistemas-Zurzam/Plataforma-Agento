<?php

namespace App\Modules\Nominas\Domain\BbvaNetCash;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Str;

/**
 * El macro NO exige un nombre de archivo rígido: `Application.
 * GetSaveAsFilename("BBVAHABE.txt", ...)` / `"BBVAH4Cat.txt"` es solo el
 * nombre SUGERIDO en el diálogo "Guardar como" del propio Excel — el
 * usuario puede escribir cualquier otro nombre ahí sin que el VBA lo
 * valide ni lo rechace. Confirmado leyendo el VBA completo (no hay ninguna
 * comprobación de nombre en `Grabar()`).
 *
 * Por eso se usa una convención propia de Agento, NUNCA declarada como
 * nombre oficial BBVA.
 */
final class BbvaNetCashFilenameBuilder
{
    public static function construir(Empresa $empresa, CicloRemunerativo $ciclo, string $subtipo): string
    {
        $periodo = $ciclo->fecha_inicio->format('Y_m');
        $etiquetaSubtipo = $subtipo === '4' ? '4TA' : '5TA';

        return 'BBVA_NETCASH_'.Str::upper(Str::slug($empresa->nombre_comercial, '_'))."_{$etiquetaSubtipo}_{$periodo}.txt";
    }
}
