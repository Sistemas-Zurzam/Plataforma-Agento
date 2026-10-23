<?php

namespace App\Modules\Nominas\Infrastructure\PlanillaPagada\Export;

use App\Modules\Configuracion\Models\Empresa;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Aproximado de la planilla del mes (pestaña "Previsualización") con las
 * mismas columnas que la tabla. Documento referencial: no es la planilla
 * oficial ni proviene de un ciclo calculado.
 */
final class PrevisualizacionPlanillaExcelExporter
{
    private const COLUMNAS_MONTO = ['C', 'F', 'G', 'H', 'I'];

    /**
     * @param  array<int, array<string, mixed>>  $filas  BoletaService::previsualizarPlanilla()
     */
    public static function generar(Empresa $empresa, string $fechaInicio, string $fechaFin, string $hoy, array $filas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Previsualización');

        $hoja->fromArray(['Empresa', $empresa->nombre_comercial], null, 'A1');
        $hoja->fromArray(['Asistencia', "{$fechaInicio} a {$fechaFin}"], null, 'A2');
        $hoja->fromArray(['Generado al', $hoy.' — aproximado referencial, no es la planilla oficial'], null, 'A3');

        $hoja->fromArray([
            'Colaborador', 'Cargo', 'Sueldo base', 'Faltas', 'Tardanza (min)', 'Total ingresos', 'Descuentos',
            'Neto estimado (mes completo)', 'Básico devengado a hoy', 'Días devengados', 'Estado',
        ], null, 'A5');

        $fila = 6;
        foreach ($filas as $registro) {
            $calculable = $registro['estado'] === 'calculable';
            $hoja->setCellValue("A{$fila}", $registro['nombre']);
            $hoja->setCellValue("B{$fila}", $registro['cargo'] ?? '—');

            if ($calculable) {
                $hoja->setCellValue("C{$fila}", (float) $registro['sueldo_basico']);
                $hoja->setCellValue("D{$fila}", $registro['asistencia_procesada'] ? (float) $registro['dias_falta'] : 'Sin procesar');
                $hoja->setCellValue("E{$fila}", $registro['asistencia_procesada'] ? (int) $registro['minutos_tardanza'] : 'Sin procesar');
                $hoja->setCellValue("F{$fila}", (float) $registro['total_ingresos']);
                $hoja->setCellValue("G{$fila}", (float) $registro['total_egresos']);
                $hoja->setCellValue("H{$fila}", (float) $registro['neto_a_pagar']);
                if ($registro['basico_devengado'] !== null) {
                    $hoja->setCellValue("I{$fila}", (float) $registro['basico_devengado']);
                    $hoja->setCellValue("J{$fila}", (float) $registro['dias_devengados']);
                } else {
                    $hoja->setCellValue("I{$fila}", 'No aplica (RH)');
                }
                $hoja->setCellValue("K{$fila}", 'Calculable');
            } else {
                $hoja->setCellValue("K{$fila}", 'No calculable: '.$registro['motivo']);
                $hoja->getStyle("K{$fila}")->getFont()->getColor()->setARGB('FFC0392B');
            }
            $fila++;
        }

        $ultima = max(6, $fila - 1);
        $hoja->setCellValue("A{$fila}", 'Total');
        foreach (self::COLUMNAS_MONTO as $columna) {
            $hoja->setCellValue("{$columna}{$fila}", "=SUM({$columna}6:{$columna}{$ultima})");
        }
        $hoja->getStyle("A{$fila}:K{$fila}")->getFont()->setBold(true);

        foreach (self::COLUMNAS_MONTO as $columna) {
            $hoja->getStyle("{$columna}6:{$columna}{$fila}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $hoja->getStyle('A1:A3')->getFont()->setBold(true);
        $hoja->getStyle('A5:K5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0B4F94']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $hoja->freezePane('A6');
        $hoja->setAutoFilter("A5:K{$ultima}");

        foreach (['A' => 34, 'B' => 22, 'C' => 13, 'D' => 9, 'E' => 11, 'F' => 14, 'G' => 13, 'H' => 16, 'I' => 16, 'J' => 11, 'K' => 40] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        $flujo = fopen('php://temp', 'w+b');
        (new Xlsx($libro))->save($flujo);
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }
}
