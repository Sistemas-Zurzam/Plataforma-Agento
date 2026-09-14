<?php

namespace App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export;

use App\Modules\Nominas\Application\ReporteEjecutivo\ReporteEjecutivoRemuneracionesCalculador;
use App\Modules\Nominas\Models\Boleta;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Vista gerencial consolidada de la planilla pagada de un período, para
 * TODAS las empresas autorizadas del usuario a la vez: desglosa bruto,
 * AFP/ONP y ESSALUD por colaborador agrupado por empresa (hoja
 * Detalle_Planilla, con subtotal y color por empresa) y resume esos mismos
 * totales en lenguaje llano (hoja Reporte_Ejecutivo). El cálculo en sí vive
 * en ReporteEjecutivoRemuneracionesCalculador (compartido con la vista
 * imprimible en PDF) — esta clase solo se encarga de la hoja de cálculo.
 */
final class ReporteEjecutivoRemuneracionesExcelExporter
{
    /** Un color por empresa (claro para las filas de detalle, oscuro para su fila de subtotal), rotando si hay más de 5. */
    private const PALETA_EMPRESAS = [
        ['claro' => 'FFD9EAF7', 'oscuro' => 'FF0B4F94'],
        ['claro' => 'FFE2F0D9', 'oscuro' => 'FF2E7D32'],
        ['claro' => 'FFFFF2CC', 'oscuro' => 'FF8A6D00'],
        ['claro' => 'FFEAE0F5', 'oscuro' => 'FF5B2C87'],
        ['claro' => 'FFD9F0EE', 'oscuro' => 'FF007A6E'],
    ];

    /**
     * @param  string  $periodo  Etiqueta legible del período consolidado (ej. "Julio 2026").
     * @param  Collection<int, Boleta>  $boletas  De una o varias empresas, con `colaborador`,
     *   `conceptos.concepto` y `empresa` ya precargados, y ya ordenadas por empresa y luego por colaborador.
     * @param  Collection<int, float>  $reintegrosPorBoleta  Ver ReporteEjecutivoRemuneracionesCalculador::filas().
     */
    public static function generar(string $periodo, Collection $boletas, Collection $reintegrosPorBoleta = new Collection()): string
    {
        $filas = ReporteEjecutivoRemuneracionesCalculador::filas($periodo, $boletas, $reintegrosPorBoleta);
        $porEmpresa = ReporteEjecutivoRemuneracionesCalculador::agruparPorEmpresa($filas);
        $totalGeneral = ReporteEjecutivoRemuneracionesCalculador::totalGeneral($filas);

        $libro = new Spreadsheet;
        self::llenarHojaDetalle($libro->getActiveSheet(), $filas, $porEmpresa, $totalGeneral);
        self::llenarHojaEjecutivo($libro->createSheet(), $periodo, $filas, $totalGeneral);
        $libro->setActiveSheetIndex(0);

        $flujo = fopen('php://temp', 'w+b');
        // Ver PlanillaPagadaExcelExporter::generar() — PhpSpreadsheet expone
        // ruido binario de punto flotante al reconstruir la parte decimal de
        // cada float, lo que hace que Excel "repare" el archivo al abrirlo.
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

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  Collection<string, array{filas: Collection, colaboradores: int, subtotal: array<string, float>}>  $porEmpresa
     * @param  array<string, float>  $totalGeneral
     */
    private static function llenarHojaDetalle(Worksheet $hoja, Collection $filas, Collection $porEmpresa, array $totalGeneral): void
    {
        $hoja->setTitle('Detalle_Planilla');

        $hoja->fromArray([
            'Empresa', 'Periodo', 'DNI', 'Colaborador', 'Tipo', 'Sueldo bruto', 'Bonos / HE',
            'Base AFP', 'AFP 10%', 'Prima seguro', 'Comisión AFP', 'Total AFP', 'Otros descuentos',
            'Reintegros', 'Neto a pagar', 'ESSALUD', 'Costo empresa', 'Estado',
        ], null, 'A1');

        $numeroFila = 2;
        $indiceEmpresa = 0;

        foreach ($porEmpresa as $empresa => $grupo) {
            $colores = self::PALETA_EMPRESAS[$indiceEmpresa % count(self::PALETA_EMPRESAS)];
            $inicioBloque = $numeroFila;

            foreach ($grupo['filas'] as $fila) {
                $hoja->setCellValue("A{$numeroFila}", $fila['empresa']);
                $hoja->setCellValue("B{$numeroFila}", $fila['periodo']);
                $hoja->setCellValueExplicit("C{$numeroFila}", $fila['dni'], DataType::TYPE_STRING);
                $hoja->setCellValue("D{$numeroFila}", $fila['nombre']);
                $hoja->setCellValue("E{$numeroFila}", $fila['tipo']);
                $hoja->setCellValue("F{$numeroFila}", $fila['sueldo_bruto']);
                $hoja->setCellValue("G{$numeroFila}", $fila['bonos']);
                $hoja->setCellValue("H{$numeroFila}", $fila['base_afp']);
                $hoja->setCellValue("I{$numeroFila}", $fila['aporte_obligatorio']);
                $hoja->setCellValue("J{$numeroFila}", $fila['prima_seguro']);
                $hoja->setCellValue("K{$numeroFila}", $fila['comision_afp']);
                $hoja->setCellValue("L{$numeroFila}", $fila['total_afp']);
                $hoja->setCellValue("M{$numeroFila}", $fila['otros_descuentos']);
                $hoja->setCellValue("N{$numeroFila}", $fila['reintegros']);
                $hoja->setCellValue("O{$numeroFila}", $fila['neto']);
                $hoja->setCellValue("P{$numeroFila}", $fila['essalud']);
                $hoja->setCellValue("Q{$numeroFila}", $fila['costo_empresa']);
                $hoja->setCellValue("R{$numeroFila}", $fila['estado']);
                $numeroFila++;
            }

            $finBloque = $numeroFila - 1;
            $hoja->getStyle("A{$inicioBloque}:R{$finBloque}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colores['claro']]],
            ]);

            self::escribirFilaTotales($hoja, $numeroFila, "Total {$empresa} ({$grupo['colaboradores']} colaboradores)", $grupo['subtotal']);
            $hoja->getStyle("A{$numeroFila}:R{$numeroFila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colores['oscuro']]],
            ]);

            $numeroFila++;
            $indiceEmpresa++;
        }

        $filaTotalGeneral = $numeroFila;
        self::escribirFilaTotales($hoja, $filaTotalGeneral, 'Total general ('.$filas->count().' colaboradores)', $totalGeneral);
        $azul = '0B4F94';
        $hoja->getStyle("A{$filaTotalGeneral}:R{$filaTotalGeneral}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);

        $hoja->getStyle('A1:R1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);

        $hoja->getStyle("F2:Q{$filaTotalGeneral}")->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle("C2:C{$filaTotalGeneral}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A2');
        $hoja->setAutoFilter("A1:R".($filaTotalGeneral - 1));

        foreach ([
            'A' => 18, 'B' => 16, 'C' => 16, 'D' => 34, 'E' => 12,
            'F' => 16, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14,
            'K' => 14, 'L' => 14, 'M' => 16, 'N' => 16, 'O' => 16, 'P' => 14, 'Q' => 16, 'R' => 12,
        ] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    /** @param  array<string, float>  $subtotal */
    private static function escribirFilaTotales(Worksheet $hoja, int $fila, string $etiqueta, array $subtotal): void
    {
        $hoja->setCellValue("A{$fila}", $etiqueta);
        $hoja->mergeCells("A{$fila}:E{$fila}");
        $hoja->setCellValue("F{$fila}", $subtotal['sueldo_bruto']);
        $hoja->setCellValue("G{$fila}", $subtotal['bonos']);
        $hoja->setCellValue("H{$fila}", $subtotal['base_afp']);
        $hoja->setCellValue("I{$fila}", $subtotal['aporte_obligatorio']);
        $hoja->setCellValue("J{$fila}", $subtotal['prima_seguro']);
        $hoja->setCellValue("K{$fila}", $subtotal['comision_afp']);
        $hoja->setCellValue("L{$fila}", $subtotal['total_afp']);
        $hoja->setCellValue("M{$fila}", $subtotal['otros_descuentos']);
        $hoja->setCellValue("N{$fila}", $subtotal['reintegros']);
        $hoja->setCellValue("O{$fila}", $subtotal['neto']);
        $hoja->setCellValue("P{$fila}", $subtotal['essalud']);
        $hoja->setCellValue("Q{$fila}", $subtotal['costo_empresa']);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  array<string, float>  $totalGeneral
     */
    private static function llenarHojaEjecutivo(Worksheet $hoja, string $periodo, Collection $filas, array $totalGeneral): void
    {
        $hoja->setTitle('Reporte_Ejecutivo');

        $totalEmpresas = $filas->pluck('empresa')->unique()->count();
        $totalBruto = round($totalGeneral['sueldo_bruto'] + $totalGeneral['bonos'], 2);
        $totalAfp = $totalGeneral['total_afp'];
        $totalReintegros = $totalGeneral['reintegros'];
        $totalNeto = $totalGeneral['neto'];
        $totalEssalud = $totalGeneral['essalud'];
        $totalCostoEmpresa = $totalGeneral['costo_empresa'];
        // Con reintegros positivos, el neto puede superar al bruto — se
        // resta el ajuste para que "Descuentos" siga siendo solo lo
        // retenido al colaborador, no un neto de ambos efectos.
        $totalDescuentos = round($totalBruto - $totalNeto + $totalReintegros, 2);

        $azul = '0B4F94';

        $hoja->setCellValue('A1', 'REPORTE EJECUTIVO DE REMUNERACIONES');
        $hoja->mergeCells('A1:E1');
        $hoja->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getRowDimension(1)->setRowHeight(26);

        $hoja->fromArray([
            ['Periodo', $periodo],
            ['Empresas incluidas', $totalEmpresas],
            ['Fecha de reporte', now()->format('d/m/Y')],
            ['Responsable', 'RR. HH.'],
        ], null, 'A3');
        $hoja->getStyle('A3:A6')->getFont()->setBold(true);

        $hoja->fromArray(['Colaboradores', 'Bruto', 'Descuentos', 'Total AFP', 'Reintegros', 'Neto pagado', 'ESSALUD', 'Costo empresa'], null, 'A8');
        $hoja->fromArray([$filas->count(), $totalBruto, $totalDescuentos, $totalAfp, $totalReintegros, $totalNeto, $totalEssalud, $totalCostoEmpresa], null, 'A9');
        $hoja->getStyle('A8:H8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->getStyle('B9:H9')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        $hoja->setCellValue('A11', 'LECTURA EJECUTIVA');
        $hoja->getStyle('A11')->getFont()->setBold(true);

        $hoja->fromArray(['Concepto', 'Qué representa', 'Importe', 'Quién lo recibe / asume'], null, 'A12');
        $hoja->getStyle('A12:D12')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->fromArray([
            ['Remuneración bruta', 'Importe antes de descuentos', $totalBruto, 'Colaborador'],
            ['AFP / ONP', 'Aporte obligatorio + prima + comisión, según corresponda', $totalAfp, 'AFP / ONP'],
            ['Neto pagado', 'Importe efectivamente entregado al colaborador', $totalNeto, 'Colaborador'],
            ['Costo empresa', 'Bruto + aportes patronales aplicables (ESSALUD / SIS)', $totalCostoEmpresa, 'Empresa'],
        ], null, 'A13');
        $hoja->getStyle('C13:C16')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        foreach (['A' => 24, 'B' => 46, 'C' => 18, 'D' => 22, 'E' => 16, 'F' => 16, 'G' => 14, 'H' => 18] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }
}
