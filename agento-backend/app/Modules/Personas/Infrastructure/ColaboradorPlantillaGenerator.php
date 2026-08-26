<?php

namespace App\Modules\Personas\Infrastructure;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Genera la plantilla .xlsx de importación de colaboradores: una fila por
 * colaborador. numero_documento y las fechas quedan forzadas a formato
 * texto ('@') para que Excel nunca las reinterprete como número/fecha
 * serial — mismo cuidado que en HorarioPlantillaGenerator, por el bug real
 * de DNI con cero a la izquierda que ya corregimos en marcaciones.
 */
class ColaboradorPlantillaGenerator
{
    private const ENCABEZADOS = [
        'sede', 'area', 'horario', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento',
        'fecha_nacimiento', 'celular_colaborador', 'celular_referencia', 'email', 'direccion',
        'cargo', 'tipo_contrato', 'tipo_trabajador', 'regimen_laboral', 'modalidad_trabajo',
        'fecha_ingreso', 'fecha_fin_contrato', 'salario', 'moneda_salario', 'periodicidad_pago',
        'asignacion_familiar', 'sistema_previsional',
    ];

    /** Columnas que deben quedar en formato texto para evitar interpretación numérica/fecha de Excel. */
    private const COLUMNAS_TEXTO = ['G', 'H', 'R', 'S'];

    public function generar(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Colaboradores');

        foreach (self::ENCABEZADOS as $indice => $titulo) {
            $columna = $this->columna($indice);
            $hoja->setCellValue("{$columna}1", $titulo);
        }
        $ultimaColumna = $this->columna(count(self::ENCABEZADOS) - 1);
        $hoja->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true);

        foreach (self::COLUMNAS_TEXTO as $columna) {
            $hoja->getStyle("{$columna}2:{$columna}200")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        $this->llenarEjemplo($hoja);
        $this->agregarValidaciones($hoja);

        foreach (range('A', $ultimaColumna) as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }

        return $libro;
    }

    private function columna(int $indice): string
    {
        return chr(ord('A') + $indice);
    }

    private function llenarEjemplo(Worksheet $hoja): void
    {
        $ejemplo = [
            'sede' => 'Sede Principal',
            'area' => 'Sistemas',
            'horario' => '8:00 - 18:00 | 9h',
            'nombres' => 'JUAN CARLOS',
            'apellidos' => 'PEREZ RAMIREZ',
            'tipo_documento' => 'dni',
            'numero_documento' => '09876543',
            'fecha_nacimiento' => '1995-05-20',
            'celular_colaborador' => '987654321',
            'celular_referencia' => '912345678',
            'email' => 'juan.perez@ejemplo.com',
            'direccion' => 'Av. Ejemplo 123',
            'cargo' => 'Analista',
            'tipo_contrato' => 'indefinido',
            'tipo_trabajador' => 'trabajador',
            'regimen_laboral' => 'General',
            'modalidad_trabajo' => 'presencial',
            'fecha_ingreso' => '2026-01-01',
            'fecha_fin_contrato' => '',
            'salario' => '2500',
            'moneda_salario' => 'PEN',
            'periodicidad_pago' => 'mensual',
            'asignacion_familiar' => '',
            'sistema_previsional' => 'onp',
        ];

        foreach (self::ENCABEZADOS as $indice => $campo) {
            $columna = $this->columna($indice);
            $hoja->setCellValueExplicit("{$columna}2", $ejemplo[$campo], DataType::TYPE_STRING);
        }
    }

    private function agregarValidaciones(Worksheet $hoja): void
    {
        $this->listaDesplegable($hoja, 'F2:F200', 'dni,ce,pasaporte');
        $this->listaDesplegable($hoja, 'N2:N200', 'plazo_fijo,indefinido,locacion_servicios,practicas');
        $this->listaDesplegable($hoja, 'O2:O200', 'trabajador,practicante,locador');
        $this->listaDesplegable($hoja, 'P2:P200', 'General,Micro Empresa,Pequeña Empresa,Locacion de Servicios');
        $this->listaDesplegable($hoja, 'Q2:Q200', 'presencial,remoto,hibrido');
        $this->listaDesplegable($hoja, 'U2:U200', 'PEN,USD');
        $this->listaDesplegable($hoja, 'V2:V200', 'mensual,quincenal,semanal');
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
