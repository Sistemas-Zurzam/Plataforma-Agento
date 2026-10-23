<?php

namespace App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export;

use App\Modules\Nominas\Application\ReporteEjecutivo\ReporteEjecutivoRemuneracionesCalculador;
use App\Modules\Nominas\Models\Boleta;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Planilla pagada del período a detalle, para TODAS las empresas
 * autorizadas del usuario a la vez: una columna por concepto pagado (bonos
 * y HE separados con su total, descuentos segmentados por concepto),
 * agrupado por empresa con subtotal y color por empresa (hoja
 * Detalle_Planilla), y un resumen en lenguaje llano (hoja
 * Reporte_Ejecutivo). El cálculo vive en
 * ReporteEjecutivoRemuneracionesCalculador — esta clase solo se encarga de
 * la hoja de cálculo.
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

    private const AZUL = 'FF0B4F94';

    /** Encabezado de las columnas de total (Total bonos, Total HE, Neto…), un tono más oscuro. */
    private const AZUL_TOTAL = 'FF083A6E';

    /** Columnas fijas antes de los montos: Empresa, Periodo, DNI, Colaborador, Área, Cargo, Tipo. */
    private const COLUMNAS_FIJAS = 7;

    /**
     * @param  string  $periodo  Etiqueta legible del período consolidado (ej. "Julio 2026").
     * @param  Collection<int, Boleta>  $boletas  De una o varias empresas, con `colaborador.area`,
     *   `conceptos.concepto`, `conceptos.conceptoDefinicion` y `empresa` ya precargados, y ya
     *   ordenadas por empresa y luego por colaborador.
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $conceptosPorBoleta  ConceptosEfectivosPorBoleta::resolver().
     */
    public static function generar(string $periodo, Collection $boletas, Collection $conceptosPorBoleta): string
    {
        return self::generarDesdeFilas(
            $periodo,
            ReporteEjecutivoRemuneracionesCalculador::filas($periodo, $boletas),
            $conceptosPorBoleta,
        );
    }

    /**
     * Mismo libro que generar() a partir de filas ya armadas (claves de
     * ReporteEjecutivoRemuneracionesCalculador::filas(): boleta_id, empresa,
     * periodo, dni, nombre, area, cargo, tipo, estado) — lo usa el
     * Proyectado de planilla, que no tiene boletas persistidas.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  Collection<int|string, Collection<int, array<string, mixed>>>  $conceptosPorFila  Indexado por `boleta_id` de cada fila.
     * @param  list<array{nombre: string, cargo: ?string, motivo: string}>  $noCalculables  Hoja aparte, solo si hay.
     */
    public static function generarDesdeFilas(string $periodo, Collection $filas, Collection $conceptosPorFila, string $titulo = 'REPORTE EJECUTIVO DE REMUNERACIONES', array $noCalculables = []): string
    {
        ['columnas' => $columnas, 'filas' => $filasDetalle] = ReporteEjecutivoRemuneracionesCalculador::detalle($filas, $conceptosPorFila);

        $libro = new Spreadsheet;
        $totalGeneral = self::llenarHojaDetalle($libro->getActiveSheet(), $columnas, $filasDetalle);
        self::llenarHojaEjecutivo($libro->createSheet(), $titulo, $periodo, $filasDetalle, $totalGeneral);
        if ($noCalculables !== []) {
            self::llenarHojaNoCalculables($libro->createSheet(), $noCalculables);
        }
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
     * Fila 1: grupo de columnas (Ingresos, Bonos, Horas extra, AFP / ONP,
     * Otros descuentos…). Fila 2: concepto. Datos desde la fila 3, con
     * subtotal por empresa y total general.
     *
     * @param  list<array{clave: string, titulo: string, grupo: string, total: bool}>  $columnas
     * @param  Collection<int, array<string, mixed>>  $filas  ReporteEjecutivoRemuneracionesCalculador::detalle()['filas'].
     * @return array<string, float> Total general por clave de columna.
     */
    private static function llenarHojaDetalle(Worksheet $hoja, array $columnas, Collection $filas): array
    {
        $hoja->setTitle('Detalle_Planilla');

        $primeraMonto = self::COLUMNAS_FIJAS + 1;
        $ultimaMonto = self::COLUMNAS_FIJAS + count($columnas);
        $columnaEstado = self::letra($ultimaMonto + 1);
        $claves = array_column($columnas, 'clave');

        $hoja->setCellValue('A1', 'Colaborador');
        $hoja->mergeCells('A1:'.self::letra(self::COLUMNAS_FIJAS).'1');
        $hoja->fromArray(['Empresa', 'Periodo', 'DNI', 'Colaborador', 'Área', 'Cargo', 'Tipo'], null, 'A2');
        $hoja->setCellValue("{$columnaEstado}2", 'Estado');
        $hoja->getStyle("A1:{$columnaEstado}2")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $inicioGrupo = $primeraMonto;
        foreach ($columnas as $i => $columna) {
            $indice = $primeraMonto + $i;
            $hoja->setCellValue(self::letra($indice).'2', $columna['titulo']);
            if ($columna['total']) {
                $hoja->getStyle(self::letra($indice).'2')->getFill()->getStartColor()->setARGB(self::AZUL_TOTAL);
            }

            // Cierra el grupo de la fila 1 cuando cambia en la siguiente columna.
            if (($columnas[$i + 1]['grupo'] ?? null) !== $columna['grupo']) {
                $hoja->setCellValue(self::letra($inicioGrupo).'1', $columna['grupo']);
                if ($indice > $inicioGrupo) {
                    $hoja->mergeCells(self::letra($inicioGrupo).'1:'.self::letra($indice).'1');
                }
                $inicioGrupo = $indice + 1;
            }
        }

        $numeroFila = 3;
        $indiceEmpresa = 0;

        foreach ($filas->groupBy('empresa') as $empresa => $grupo) {
            $colores = self::PALETA_EMPRESAS[$indiceEmpresa % count(self::PALETA_EMPRESAS)];
            $inicioBloque = $numeroFila;

            foreach ($grupo as $fila) {
                $hoja->setCellValue("A{$numeroFila}", $fila['empresa']);
                $hoja->setCellValue("B{$numeroFila}", $fila['periodo']);
                $hoja->setCellValueExplicit("C{$numeroFila}", $fila['dni'], DataType::TYPE_STRING);
                $hoja->setCellValue("D{$numeroFila}", $fila['nombre']);
                $hoja->setCellValue("E{$numeroFila}", $fila['area']);
                $hoja->setCellValue("F{$numeroFila}", $fila['cargo']);
                $hoja->setCellValue("G{$numeroFila}", $fila['tipo']);
                foreach ($claves as $i => $clave) {
                    $hoja->setCellValue(self::letra($primeraMonto + $i).$numeroFila, $fila['montos'][$clave]);
                }
                $hoja->setCellValue("{$columnaEstado}{$numeroFila}", $fila['estado']);
                $numeroFila++;
            }

            $hoja->getStyle("A{$inicioBloque}:{$columnaEstado}".($numeroFila - 1))->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colores['claro']]],
            ]);

            self::escribirFilaTotales($hoja, $numeroFila, "Total {$empresa} ({$grupo->count()} colaboradores)", $claves, self::sumar($grupo, $claves));
            $hoja->getStyle("A{$numeroFila}:{$columnaEstado}{$numeroFila}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colores['oscuro']]],
            ]);

            $numeroFila++;
            $indiceEmpresa++;
        }

        $totalGeneral = self::sumar($filas, $claves);
        $filaTotalGeneral = $numeroFila;
        self::escribirFilaTotales($hoja, $filaTotalGeneral, 'Total general ('.$filas->count().' colaboradores)', $claves, $totalGeneral);
        $hoja->getStyle("A{$filaTotalGeneral}:{$columnaEstado}{$filaTotalGeneral}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
        ]);

        $hoja->getRowDimension(2)->setRowHeight(32);
        $hoja->getStyle(self::letra($primeraMonto).'3:'.self::letra($ultimaMonto).$filaTotalGeneral)
            ->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle("C3:C{$filaTotalGeneral}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('E3');
        $hoja->setAutoFilter("A2:{$columnaEstado}".($filaTotalGeneral - 1));

        foreach (['A' => 18, 'B' => 14, 'C' => 12, 'D' => 34, 'E' => 18, 'F' => 22, 'G' => 10] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
        foreach (range($primeraMonto, $ultimaMonto) as $indice) {
            $hoja->getColumnDimension(self::letra($indice))->setWidth(15);
        }
        $hoja->getColumnDimension($columnaEstado)->setWidth(12);

        return $totalGeneral;
    }

    private static function letra(int $indice): string
    {
        return Coordinate::stringFromColumnIndex($indice);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  list<string>  $claves
     * @return array<string, float>
     */
    private static function sumar(Collection $filas, array $claves): array
    {
        $totales = [];
        foreach ($claves as $clave) {
            $totales[$clave] = round((float) $filas->sum(fn (array $fila) => $fila['montos'][$clave]), 2);
        }

        return $totales;
    }

    /**
     * @param  list<string>  $claves
     * @param  array<string, float>  $totales
     */
    private static function escribirFilaTotales(Worksheet $hoja, int $fila, string $etiqueta, array $claves, array $totales): void
    {
        $hoja->setCellValue("A{$fila}", $etiqueta);
        $hoja->mergeCells("A{$fila}:".self::letra(self::COLUMNAS_FIJAS).$fila);
        foreach ($claves as $i => $clave) {
            $hoja->setCellValue(self::letra(self::COLUMNAS_FIJAS + 1 + $i).$fila, $totales[$clave]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  array<string, float>  $totalGeneral  Por clave de columna de detalle().
     */
    private static function llenarHojaEjecutivo(Worksheet $hoja, string $titulo, string $periodo, Collection $filas, array $totalGeneral): void
    {
        $hoja->setTitle('Reporte_Ejecutivo');

        $totalEmpresas = $filas->pluck('empresa')->unique()->count();
        $totalBruto = $totalGeneral['total_ingresos'];
        $totalDescuentos = $totalGeneral['total_descuentos'];
        $totalAfp = $totalGeneral['total_afp'];
        $totalNeto = $totalGeneral['neto'];
        $totalEssalud = $totalGeneral['essalud'];
        $totalCostoEmpresa = $totalGeneral['costo_empresa'];

        $hoja->setCellValue('A1', $titulo);
        $hoja->mergeCells('A1:E1');
        $hoja->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
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

        $hoja->fromArray(['Colaboradores', 'Bruto', 'Descuentos', 'Total AFP / ONP', 'Neto pagado', 'ESSALUD', 'Costo empresa'], null, 'A8');
        $hoja->fromArray([$filas->count(), $totalBruto, $totalDescuentos, $totalAfp, $totalNeto, $totalEssalud, $totalCostoEmpresa], null, 'A9');
        $hoja->getStyle('A8:G8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
        ]);
        $hoja->getStyle('B9:G9')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        $hoja->setCellValue('A11', 'LECTURA EJECUTIVA');
        $hoja->getStyle('A11')->getFont()->setBold(true);

        $hoja->fromArray(['Concepto', 'Qué representa', 'Importe', 'Quién lo recibe / asume'], null, 'A12');
        $hoja->getStyle('A12:D12')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
        ]);
        $hoja->fromArray([
            ['Remuneración bruta', 'Importe antes de descuentos (incluye reintegros)', $totalBruto, 'Colaborador'],
            ['AFP / ONP', 'Aporte obligatorio + prima + comisión, según corresponda', $totalAfp, 'AFP / ONP'],
            ['Neto pagado', 'Importe efectivamente entregado al colaborador', $totalNeto, 'Colaborador'],
            ['Costo empresa', 'Bruto + aportes patronales aplicables (ESSALUD / SIS)', $totalCostoEmpresa, 'Empresa'],
        ], null, 'A13');
        $hoja->getStyle('C13:C16')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        foreach (['A' => 24, 'B' => 46, 'C' => 18, 'D' => 22, 'E' => 16, 'F' => 16, 'G' => 18] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    /** @param  list<array{nombre: string, cargo: ?string, motivo: string}>  $noCalculables */
    private static function llenarHojaNoCalculables(Worksheet $hoja, array $noCalculables): void
    {
        $hoja->setTitle('No_calculables');
        $hoja->fromArray(['Colaborador', 'Cargo', 'Motivo'], null, 'A1');
        $hoja->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
        ]);
        foreach ($noCalculables as $i => $fila) {
            $hoja->fromArray([$fila['nombre'], $fila['cargo'] ?? '', $fila['motivo']], null, 'A'.($i + 2));
        }
        foreach (['A' => 34, 'B' => 22, 'C' => 90] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }
}
