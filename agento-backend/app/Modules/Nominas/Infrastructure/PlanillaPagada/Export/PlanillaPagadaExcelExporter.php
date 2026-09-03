<?php

namespace App\Modules\Nominas\Infrastructure\PlanillaPagada\Export;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PlanillaPagadaExcelExporter
{
    /**
     * @param  Collection<int, Boleta>  $boletas
     */
    public static function generar(CicloRemunerativo $ciclo, Collection $boletas): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Planilla pagada');

        $totalIngresos = $boletas->sum(fn ($boleta) => (float) $boleta->total_ingresos);
        $totalDescuentos = $boletas->sum(fn ($boleta) => (float) $boleta->total_egresos);
        $totalNeto = $boletas->sum(fn ($boleta) => (float) $boleta->neto_a_pagar);

        $hoja->fromArray([
            'Empresa', $ciclo->empresa->nombre_comercial,
            'Ingresos totales', $totalIngresos,
            'Descuentos totales', $totalDescuentos,
            'Neto pagado', $totalNeto,
        ], null, 'A1');

        $hoja->fromArray(['DNI', 'Nombre y apellidos', 'Ingresos', 'Descuentos', 'Neto pagado', 'Banco'], null, 'A3');

        foreach ($boletas as $indice => $boleta) {
            $fila = $indice + 4;
            $colaborador = $boleta->colaborador;

            $hoja->setCellValueExplicit("A{$fila}", (string) $colaborador?->numero_documento, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')));
            $hoja->setCellValue("C{$fila}", (float) $boleta->total_ingresos);
            $hoja->setCellValue("D{$fila}", (float) $boleta->total_egresos);
            $hoja->setCellValue("E{$fila}", (float) $boleta->neto_a_pagar);
            $hoja->setCellValue("F{$fila}", $boleta->datosPago?->banco?->nombre ?? 'Sin banco');
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getStyle('A3:F3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->getStyle('D1')->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle('F1')->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle('H1')->getNumberFormat()->setFormatCode('S/ #,##0.00');

        $ultimaFila = max(4, $boletas->count() + 3);
        $hoja->getStyle("C4:E{$ultimaFila}")->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle("A4:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:F{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(24);

        foreach (['A' => 16, 'B' => 38, 'C' => 17, 'D' => 17, 'E' => 17, 'F' => 24] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
        $hoja->getColumnDimension('G')->setWidth(17);
        $hoja->getColumnDimension('H')->setWidth(17);

        $hoja->getHeaderFooter()->setOddHeader('&L'.$ciclo->empresa->nombre_comercial.'&R'.$ciclo->nombre);

        $flujo = fopen('php://temp', 'w+b');
        (new Xlsx($libro))->save($flujo);
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }
}
