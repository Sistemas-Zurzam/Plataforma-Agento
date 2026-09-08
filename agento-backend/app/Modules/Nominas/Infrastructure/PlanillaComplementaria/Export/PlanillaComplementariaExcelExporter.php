<?php

namespace App\Modules\Nominas\Infrastructure\PlanillaComplementaria\Export;

use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mismo patrón que PlanillaPagadaExcelExporter (exporter estático, una hoja
 * con resumen + detalle) — pero una fila por DETALLE, no por complementaria:
 * un reintegro con varios colaboradores debe listarlos todos, no colapsarse
 * en una sola línea.
 */
final class PlanillaComplementariaExcelExporter
{
    /**
     * @param  Collection<int, PlanillaComplementaria>  $items  Con `detalles.colaborador` ya precargado (ver PlanillaComplementariaService::listar()).
     */
    public static function generar(CicloRemunerativo $ciclo, Collection $items): string
    {
        $filas = $items->flatMap(fn (PlanillaComplementaria $item) => $item->detalles->map(fn ($detalle) => [
            'reintegro' => $item->nombre,
            'tipo' => self::tipo($detalle->calculo_snapshot ?? []),
            'estado' => $item->estado,
            'colaborador' => trim(($detalle->colaborador?->nombres ?? '').' '.($detalle->colaborador?->apellidos ?? '')),
            'documento' => $detalle->colaborador?->numero_documento,
            'neto_original' => (float) $detalle->neto_original,
            'neto_recalculado' => (float) $detalle->neto_recalculado,
            'diferencia_neta' => (float) $detalle->diferencia_neta,
            'aprobado_at' => $item->aprobado_at,
            'pagado_at' => $item->pagado_at,
            'referencia_pago' => $item->referencia_pago,
            'motivo' => $item->motivo,
        ]))->values();

        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Planillas complementarias');

        $totalAPagar = $filas->sum(fn ($f) => max(0, $f['diferencia_neta']));
        $totalADescontar = abs($filas->sum(fn ($f) => min(0, $f['diferencia_neta'])));

        $hoja->fromArray([
            'Empresa', $ciclo->empresa->nombre_comercial,
            'Ciclo', $ciclo->nombre,
            'Reintegros', $items->count(),
            'Total a pagar', $totalAPagar,
            'Total a descontar', $totalADescontar,
        ], null, 'A1');

        $hoja->fromArray(['Reintegro', 'Tipo', 'Estado', 'Colaborador', 'DNI', 'Neto ya cubierto', 'Neto corregido', 'Diferencia', 'Aprobado', 'Pagado', 'Referencia de pago', 'Motivo'], null, 'A3');

        foreach ($filas as $indice => $f) {
            $fila = $indice + 4;
            $hoja->setCellValue("A{$fila}", $f['reintegro']);
            $hoja->setCellValue("B{$fila}", $f['tipo']);
            $hoja->setCellValue("C{$fila}", $f['estado']);
            $hoja->setCellValue("D{$fila}", $f['colaborador']);
            $hoja->setCellValueExplicit("E{$fila}", (string) $f['documento'], DataType::TYPE_STRING);
            $hoja->setCellValue("F{$fila}", $f['neto_original']);
            $hoja->setCellValue("G{$fila}", $f['neto_recalculado']);
            $hoja->setCellValue("H{$fila}", $f['diferencia_neta']);
            $hoja->setCellValue("I{$fila}", $f['aprobado_at']?->format('d/m/Y H:i') ?? '');
            $hoja->setCellValue("J{$fila}", $f['pagado_at']?->format('d/m/Y H:i') ?? '');
            $hoja->setCellValue("K{$fila}", $f['referencia_pago'] ?? '');
            $hoja->setCellValue("L{$fila}", $f['motivo']);
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getStyle('A3:L3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->getStyle('H1')->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle('J1')->getNumberFormat()->setFormatCode('S/ #,##0.00');

        $ultimaFila = max(4, $filas->count() + 3);
        $hoja->getStyle("F4:H{$ultimaFila}")->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle("E4:E{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:L{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(24);

        foreach (['A' => 34, 'B' => 22, 'C' => 12, 'D' => 30, 'E' => 14, 'F' => 16, 'G' => 16, 'H' => 14, 'I' => 16, 'J' => 16, 'K' => 20, 'L' => 34] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        $hoja->getHeaderFooter()->setOddHeader('&L'.$ciclo->empresa->nombre_comercial.'&R'.$ciclo->nombre);

        $flujo = fopen('php://temp', 'w+b');
        (new Xlsx($libro))->save($flujo);
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }

    /** Infiere el tipo de reintegro desde las claves que ya usa el motor de cálculo (mismo criterio que PlanillasComplementariasModal.jsx). */
    private static function tipo(array $snapshot): string
    {
        return match (true) {
            isset($snapshot['feriado_regularizado']) => 'Feriado trabajado',
            ! empty($snapshot['descansos_semanales']) => 'Descanso semanal trabajado',
            ! empty($snapshot['reintegros_descuentos']) => 'Reintegro de descuentos',
            ! empty($snapshot['horas_extra_regularizadas']) => 'Horas extra',
            ! empty($snapshot['bonos_masivos']) => 'Bono por asistencia',
            default => 'Diferencia de ciclo',
        };
    }
}
