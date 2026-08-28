<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use ZipArchive;

/**
 * Empaqueta los .txt generados en un ZIP temporal para descarga (Sección
 * 47/48/49). Nunca se guarda en storage permanente — se crea en el
 * directorio temporal del sistema y el Controller lo elimina apenas
 * termina de enviarlo (`deleteFileAfterSend`).
 *
 * Estructura del ZIP: PLANA, sin subcarpetas (Sección 49) — los archivos
 * internos conservan exactamente su nombre oficial SUNAT
 * (PlameFilenameBuilder), solo el .zip contenedor usa un nombre amigable
 * Agento. Se prefirió plano sobre PLANILLA/RH porque el flujo real de
 * importación a PDT PLAME es "extraer todo y seleccionar cada .txt uno por
 * uno" — un árbol de carpetas no aporta nada ahí y sí puede confundir a
 * quien no esté familiarizado con el ZIP.
 */
final class PlameZipBuilder
{
    /**
     * @param  array<int, array{nombre: string, contenido: string}>  $archivos
     * @return string Ruta absoluta del .zip temporal generado.
     */
    public static function construir(array $archivos): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plame_').'.zip';

        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($archivos as $archivo) {
            $zip->addFromString($archivo['nombre'], $archivo['contenido']);
        }

        $zip->close();

        return $ruta;
    }
}
