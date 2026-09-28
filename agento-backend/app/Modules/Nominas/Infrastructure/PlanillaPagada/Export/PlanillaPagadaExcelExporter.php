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

        $totalIngresos = round($boletas->sum(fn ($boleta) => (float) $boleta->total_ingresos), 2);
        $totalDescuentos = round($boletas->sum(fn ($boleta) => (float) $boleta->total_egresos), 2);
        $totalNeto = round($boletas->sum(fn ($boleta) => (float) $boleta->neto_a_pagar), 2);

        $hoja->fromArray([
            'Empresa', $ciclo->empresa->nombre_comercial,
            'Ingresos totales', $totalIngresos,
            'Descuentos totales', $totalDescuentos,
            'Neto pagado', $totalNeto,
        ], null, 'A1');

        $hoja->fromArray(['DNI', 'Nombre y apellidos', 'Sede', 'Área', 'Cargo', 'Ingresos', 'Descuentos', 'Neto pagado', 'Banco', 'Tipo de cuenta', 'Número de cuenta', 'CCI'], null, 'A3');

        foreach ($boletas as $indice => $boleta) {
            $fila = $indice + 4;
            $colaborador = $boleta->colaborador;
            $datosPago = $boleta->datosPago;

            $hoja->setCellValueExplicit("A{$fila}", (string) $colaborador?->numero_documento, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')));
            $hoja->setCellValue("C{$fila}", $colaborador?->sede?->nombre ?? '');
            $hoja->setCellValue("D{$fila}", $colaborador?->area?->nombre ?? '');
            $hoja->setCellValue("E{$fila}", $colaborador?->cargo ?? '');
            $hoja->setCellValue("F{$fila}", round((float) $boleta->total_ingresos, 2));
            $hoja->setCellValue("G{$fila}", round((float) $boleta->total_egresos, 2));
            $hoja->setCellValue("H{$fila}", round((float) $boleta->neto_a_pagar, 2));
            $hoja->setCellValue("I{$fila}", $datosPago?->banco?->nombre ?? 'Sin banco');
            $hoja->setCellValue("J{$fila}", $datosPago?->tipo_cuenta_snapshot ?? '');
            $hoja->setCellValueExplicit("K{$fila}", (string) ($datosPago?->numero_cuenta_snapshot ?? ''), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("L{$fila}", (string) ($datosPago?->cci_snapshot ?? ''), DataType::TYPE_STRING);
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getStyle('A3:L3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->getStyle('D1')->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle('F1')->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle('H1')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        $ultimaFila = max(4, $boletas->count() + 3);
        $hoja->getStyle("F4:H{$ultimaFila}")->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle("A4:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle("K4:L{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:L{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(24);

        foreach ([
            'A' => 16, 'B' => 38, 'C' => 20, 'D' => 18, 'E' => 22,
            'F' => 17, 'G' => 17, 'H' => 17, 'I' => 24, 'J' => 16, 'K' => 20, 'L' => 22,
        ] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        $hoja->getHeaderFooter()->setOddHeader('&L'.$ciclo->empresa->nombre_comercial.'&R'.$ciclo->nombre);

        $flujo = fopen('php://temp', 'w+b');
        // PhpSpreadsheet reconstruye la parte decimal de cada float restando
        // su parte entera (StringHelper::convertToString), lo que expone el
        // ruido binario de punto flotante (ej. 2300.09 → "2300.090000000000146")
        // y hace que Excel "repare" el archivo al abrirlo. Bajar `precision`
        // durante el guardado evita que ese ruido se escriba en el XML.
        $precisionOriginal = ini_get('precision');
        ini_set('precision', 10);
        try {
            (new Xlsx($libro))->save($flujo);
        } finally {
            ini_set('precision', $precisionOriginal);
        }
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }
}
