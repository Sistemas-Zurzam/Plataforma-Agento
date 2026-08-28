<?php

namespace App\Modules\Nominas\Infrastructure\AfpNet\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel AFPnet — archivo de CARGA, no un reporte (Sección 19 del encargo):
 * sin título, sin logo, sin cabecera decorativa, sin datos de empresa, sin
 * filtros, sin tabla visual. Columnas A-Q, filas de datos empezando en la
 * fila 1 (sin encabezado — la plantilla real que entregó negocio tampoco
 * lo lleva en el archivo de carga, solo en el ejemplo de referencia).
 *
 * Todos los campos alfanuméricos/códigos se escriben con
 * `setCellValueExplicit(..., TYPE_STRING)` — mismo cuidado que
 * ColaboradorPlantillaGenerator: nunca dejar que Excel reinterprete
 * "00123456" como 123456 o "0" como número.
 */
final class AfpNetExcelExporter
{
    /** Orden exacto de columnas A-Q — nunca cambia sin tocar el macro AFPnet. */
    private const COLUMNAS = [
        'secuencia', 'cuspp', 'tipo_documento', 'numero_documento',
        'apellido_paterno', 'apellido_materno', 'nombres',
        'relacion_laboral', 'inicio_relacion_laboral', 'cese_relacion_laboral',
        'excepcion_aportar', 'remuneracion_asegurable',
        'aporte_voluntario_con_fin', 'aporte_voluntario_sin_fin', 'aporte_voluntario_empleador',
        'tipo_trabajo', 'afp',
    ];

    /**
     * Únicas columnas verdaderamente numéricas — todo lo demás (Sección 20:
     * "Especialmente secuencia; CUSPP; tipo documento; número documento;
     * nombres/códigos; S/N; excepción; AFP") se fuerza a TEXTO por
     * default, nunca se deja a la inferencia de Excel.
     */
    private const COLUMNAS_IMPORTE = [
        'remuneracion_asegurable', 'aporte_voluntario_con_fin', 'aporte_voluntario_sin_fin', 'aporte_voluntario_empleador',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $filas  Filas ya construidas por AfpNetFilaBuilder.
     */
    public static function generar(array $filas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('AFPNET');

        foreach ($filas as $indiceFila => $fila) {
            $numeroFila = $indiceFila + 1;
            foreach (self::COLUMNAS as $indiceColumna => $campo) {
                $columna = self::columna($indiceColumna);
                $valor = $fila[$campo] ?? '';

                if (in_array($campo, self::COLUMNAS_IMPORTE, true)) {
                    // Remuneración/aportes: numérico con 2 decimales — el
                    // valor ya viene como string decimal del snapshot
                    // (Sección 21: nunca FLOAT durante el cálculo), acá
                    // solo se castea al escribir la celda numérica.
                    $hoja->setCellValue("{$columna}{$numeroFila}", (float) $valor);
                    $hoja->getStyle("{$columna}{$numeroFila}")->getNumberFormat()->setFormatCode('#,##0.00');
                } else {
                    $hoja->setCellValueExplicit("{$columna}{$numeroFila}", (string) $valor, DataType::TYPE_STRING);
                }
            }
        }

        // Forzar formato texto en toda la columna (no solo las filas
        // usadas) para que si el usuario agrega filas manualmente antes de
        // cargar, Excel no reinterprete ceros iniciales.
        foreach (self::COLUMNAS as $indiceColumna => $campo) {
            if (in_array($campo, self::COLUMNAS_IMPORTE, true)) {
                continue;
            }
            $columna = self::columna($indiceColumna);
            $hoja->getStyle("{$columna}1:{$columna}1048576")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'afpnet_').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $contenido = file_get_contents($ruta);
        unlink($ruta);

        return $contenido;
    }

    private static function columna(int $indice): string
    {
        $posicion = $indice + 1;
        $letras = '';
        while ($posicion > 0) {
            $resto = ($posicion - 1) % 26;
            $letras = chr(65 + $resto).$letras;
            $posicion = intdiv($posicion - 1, 26);
        }

        return $letras;
    }
}
