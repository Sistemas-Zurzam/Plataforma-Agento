<?php

namespace App\Modules\Asistencia\Infrastructure;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Genera la plantilla .xlsx de importación de horarios: una fila por
 * horario+día (7 filas por horario). Las columnas de hora/día/fecha se
 * fuerzan a formato texto ('@') para que Excel nunca las reinterprete como
 * número o fecha serial — la misma clase de bug que ya corregimos en el
 * importador de marcaciones (ver TransactionXlsxReader).
 */
class HorarioPlantillaGenerator
{
    private const ENCABEZADOS = [
        'nombre_horario', 'tipo_turno', 'tolerancia_minutos', 'cruza_medianoche',
        'descripcion', 'vigencia_desde', 'vigencia_hasta', 'dia', 'estado_dia',
        'hora_entrada', 'hora_salida', 'refrigerio_inicio', 'refrigerio_fin',
        'permitir_horas_extra', 'jornada_nocturna',
    ];

    private const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    /** Columnas que deben quedar en formato texto para evitar interpretación numérica/fecha de Excel. */
    private const COLUMNAS_TEXTO = ['F', 'G', 'J', 'K', 'L', 'M'];

    public function generar(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Horarios');

        foreach (self::ENCABEZADOS as $indice => $titulo) {
            $columna = chr(ord('A') + $indice);
            $hoja->setCellValue("{$columna}1", $titulo);
        }
        $hoja->getStyle('A1:O1')->getFont()->setBold(true);

        foreach (self::COLUMNAS_TEXTO as $columna) {
            $hoja->getStyle("{$columna}2:{$columna}20")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $this->llenarEjemplo($hoja);
        $this->agregarValidaciones($hoja);

        foreach (range('A', 'O') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        return $libro;
    }

    private function llenarEjemplo(Worksheet $hoja): void
    {
        $laborables = ['08:30', '08:30', '08:30', '08:30', '08:30', null, null];
        $salidas = ['17:00', '17:00', '17:00', '17:00', '17:00', null, null];

        foreach (self::DIAS as $indice => $dia) {
            $fila = $indice + 2;
            $esLaborable = $laborables[$indice] !== null;
            $hoja->setCellValueExplicit("A{$fila}", 'Horario Oficina', DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", 'normal');
            $hoja->setCellValue("C{$fila}", 15);
            $hoja->setCellValue("D{$fila}", 'No');
            $hoja->setCellValue("E{$fila}", 'Ejemplo — reemplaza esta fila');
            $hoja->setCellValueExplicit("F{$fila}", '2026-01-01', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("G{$fila}", '', DataType::TYPE_STRING);
            $hoja->setCellValue("H{$fila}", $dia);
            $hoja->setCellValue("I{$fila}", $esLaborable ? 'laborable' : 'descanso');
            $hoja->setCellValueExplicit("J{$fila}", $laborables[$indice] ?? '', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("K{$fila}", $salidas[$indice] ?? '', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("L{$fila}", $esLaborable ? '13:00' : '', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("M{$fila}", $esLaborable ? '14:00' : '', DataType::TYPE_STRING);
            $hoja->setCellValue("N{$fila}", 'No');
            $hoja->setCellValue("O{$fila}", 'No');
        }
    }

    private function agregarValidaciones(Worksheet $hoja): void
    {
        $this->listaDesplegable($hoja, 'H2:H200', implode(',', self::DIAS));
        $this->listaDesplegable($hoja, 'I2:I200', 'laborable,descanso,pendiente');
        $this->listaDesplegable($hoja, 'B2:B200', 'normal,nocturno,rotativo');
        foreach (['D', 'N', 'O'] as $columna) {
            $this->listaDesplegable($hoja, "{$columna}2:{$columna}200", 'Sí,No');
        }
    }

    /**
     * PhpSpreadsheet no tiene un "aplicar a rango" directo para validación de
     * tipo lista — se clona la misma regla celda por celda del rango.
     */
    private function listaDesplegable(Worksheet $hoja, string $rango, string $opciones): void
    {
        $validacion = new DataValidation;
        $validacion->setType(DataValidation::TYPE_LIST);
        $validacion->setErrorStyle(DataValidation::STYLE_STOP);
        $validacion->setAllowBlank(true);
        $validacion->setShowDropDown(true);
        $validacion->setFormula1('"'.$opciones.'"');

        [$inicio, $fin] = explode(':', $rango);
        $columna = preg_replace('/\d+/', '', $inicio);
        $filaInicio = (int) preg_replace('/\D+/', '', $inicio);
        $filaFin = (int) preg_replace('/\D+/', '', $fin);
        for ($fila = $filaInicio; $fila <= $filaFin; $fila++) {
            $hoja->getCell("{$columna}{$fila}")->setDataValidation(clone $validacion);
        }
    }
}
