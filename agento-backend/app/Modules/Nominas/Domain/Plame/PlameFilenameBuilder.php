<?php

namespace App\Modules\Nominas\Domain\Plame;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;

/**
 * Nombre oficial de archivo PLAME, idéntico para las 5 estructuras
 * (E7/E14/E15/E18/E20 del Anexo 3): "ffffaaaamm###########.ext" donde
 * ffff=0601 (código de formulario, fijo en las 5 hojas del Anexo 3),
 * aaaa=año del período, mm=mes del período (2 dígitos), ###########=RUC de
 * la empresa (11 dígitos) — solo cambia la extensión. Una sola clase para
 * las 5 (Sección 9): evita repetir la regla en cada Generator.
 *
 * El período (aaaa/mm) se toma de `ciclo->fecha_inicio` — Agento no separa
 * "mes de la declaración" de "mes del ciclo" (periodicidad mensual, ver
 * CicloRemunerativo), así que ambas fechas del ciclo caen normalmente en el
 * mismo mes calendario.
 */
class PlameFilenameBuilder
{
    private const CODIGO_FORMULARIO = '0601';

    public static function construir(Empresa $empresa, CicloRemunerativo $ciclo, string $extension): string
    {
        $ruc = $empresa->ruc;
        if (! preg_match('/^\d{11}$/', (string) $ruc)) {
            // RequisitoRucPlame ya debió bloquear esto antes (Sección 5) —
            // esto es el resguardo final, nunca truncar/rellenar el RUC.
            throw PlameExportException::formatoInvalido('empresa.ruc', (string) $ruc);
        }

        $aaaa = $ciclo->fecha_inicio->format('Y');
        $mm = $ciclo->fecha_inicio->format('m');

        return self::CODIGO_FORMULARIO.$aaaa.$mm.$ruc.'.'.$extension;
    }
}
