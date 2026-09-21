<?php

namespace App\Modules\Nominas\Infrastructure\BonoAsistencia\Export;

use App\Modules\Nominas\Domain\BonoAsistenciaCalculator;
use App\Modules\Nominas\Models\BonoAsistenciaLote;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel que se envía a Livex para que marque, por colaborador, si cumplió su
 * meta comercial y si aprueba el bono propuesto — mismo patrón visual que
 * PlanillaPagadaExcelExporter (PhpSpreadsheet directo).
 *
 * Las 4 últimas columnas (J-M) quedan vacías a propósito: son las que Livex
 * completa fuera de Agento antes de que el Excel se reimporte — ver
 * BonoAsistenciaXlsxReader, que busca estos MISMOS encabezados por su slug.
 */
final class BonoAsistenciaExcelExporter
{
    public static function generar(BonoAsistenciaLote $lote): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Bono de asistencia');

        $encabezados = [
            'Documento', 'Colaborador', 'Dias falta justificada', 'Dias falta injustificada',
            'Tardanzas', 'Bono base (S/)', '% propuesto', 'Monto propuesto (S/)', 'Motivo del calculo',
            'Cumplio meta comercial (Si/No)', 'Aprobado (Si/No)',
            'Corregir dias falta injustificada (opcional)', 'Observacion',
        ];
        $hoja->fromArray($encabezados, null, 'A1');

        $detalles = $lote->detalles->sortBy('colaborador_nombre_snapshot')->values();
        $calculador = new BonoAsistenciaCalculator;

        foreach ($detalles as $indice => $detalle) {
            $fila = $indice + 2;

            // El motivo nunca se persiste en bono_asistencia_lote_detalles
            // (es puro texto derivado): se recalcula aquí con los mismos
            // valores propuestos (metaComercialCumplida: null, igual que en
            // generar()) en vez de agregar una columna solo para guardarlo.
            $motivo = $calculador->calcular(
                (int) $detalle->dias_falta_justificada,
                (int) $detalle->dias_falta_injustificada,
                (int) $detalle->tardanzas,
                (float) $detalle->bono_base,
                null,
            )['motivo'];

            $hoja->setCellValueExplicit("A{$fila}", (string) $detalle->documento_snapshot, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", $detalle->colaborador_nombre_snapshot);
            $hoja->setCellValue("C{$fila}", (int) $detalle->dias_falta_justificada);
            $hoja->setCellValue("D{$fila}", (int) $detalle->dias_falta_injustificada);
            $hoja->setCellValue("E{$fila}", (int) $detalle->tardanzas);
            $hoja->setCellValue("F{$fila}", (float) $detalle->bono_base);
            $hoja->setCellValue("G{$fila}", (int) $detalle->porcentaje_propuesto);
            $hoja->setCellValue("H{$fila}", (float) $detalle->monto_propuesto);
            $hoja->setCellValue("I{$fila}", $motivo);
            // J..M quedan vacías para que Livex las complete.
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:M1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $ultimaFila = max(2, $detalles->count() + 1);
        $hoja->getStyle("F2:F{$ultimaFila}")->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle("H2:H{$ultimaFila}")->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle("A2:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');

        $hoja->freezePane('A2');
        $hoja->setAutoFilter("A1:M{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(24);

        foreach ([
            'A' => 16, 'B' => 32, 'C' => 14, 'D' => 16, 'E' => 12, 'F' => 14,
            'G' => 12, 'H' => 16, 'I' => 46, 'J' => 26, 'K' => 16, 'L' => 32, 'M' => 32,
        ] as $columna => $ancho) {
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
