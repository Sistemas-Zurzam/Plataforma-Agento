<?php

namespace App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export;

use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Vista gerencial de la planilla pagada de un ciclo: desglosa bruto, AFP/ONP
 * y ESSALUD por colaborador (hoja Detalle_Planilla) y resume esos mismos
 * totales en lenguaje llano (hoja Reporte_Ejecutivo). No recalcula nada — solo
 * reagrupa boleta_conceptos ya calculado, igual que PlanillaPagadaExcelExporter
 * reagrupa boletas para el listado de pago.
 */
final class ReporteEjecutivoRemuneracionesExcelExporter
{
    /**
     * Mismo criterio que BoletaService::CODIGOS_PREVISIONALES para el aporte
     * obligatorio: AFP y ONP son mutuamente excluyentes por colaborador
     * (sistema_previsional), nunca coexisten en la misma boleta.
     */
    private const CODIGOS_APORTE_OBLIGATORIO = ['AFP_APORTE_OBLIGATORIO', 'ONP'];

    /** Aportación patronal de salud — ESSALUD y SIS son mutuamente excluyentes según Empresa.seguro_salud. */
    private const CODIGOS_APORTACION_SALUD = ['ESSALUD', 'SIS_APORTACION'];

    /** Ingreso base según motor: SUELDO_BASICO (planilla dependiente) u HONORARIO_BRUTO (recibos por honorarios). */
    private const CODIGOS_INGRESO_BASE = ['SUELDO_BASICO', 'HONORARIO_BRUTO'];

    /**
     * @param  Collection<int, Boleta>  $boletas  Con `conceptos.concepto` ya precargado.
     */
    public static function generar(CicloRemunerativo $ciclo, Collection $boletas): string
    {
        $filas = $boletas->map(fn (Boleta $boleta) => self::calcularFila($ciclo, $boleta))->values();

        $libro = new Spreadsheet;
        self::llenarHojaDetalle($libro->getActiveSheet(), $filas);
        self::llenarHojaEjecutivo($libro->createSheet(), $ciclo, $filas);
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
     * @return array{empresa: string, periodo: string, dni: string, nombre: string, tipo: string,
     *   sueldo_bruto: float, bonos: float, base_afp: float, aporte_obligatorio: float, prima_seguro: float,
     *   comision_afp: float, total_afp: float, otros_descuentos: float, neto: float, essalud: float,
     *   costo_empresa: float, estado: string}
     */
    private static function calcularFila(CicloRemunerativo $ciclo, Boleta $boleta): array
    {
        $colaborador = $boleta->colaborador;
        $conceptos = $boleta->conceptos;

        $totalIngresos = round((float) $boleta->total_ingresos, 2);
        $sueldoBruto = self::sumarConceptos($conceptos, self::CODIGOS_INGRESO_BASE);
        $aporteObligatorio = self::sumarConceptos($conceptos, self::CODIGOS_APORTE_OBLIGATORIO);
        $primaSeguro = self::sumarConceptos($conceptos, ['AFP_PRIMA_SEGURO']);
        $comisionAfp = self::sumarConceptos($conceptos, ['AFP_COMISION']);
        $baseAfp = round((float) ($conceptos->first(
            fn (BoletaConcepto $c) => in_array($c->concepto?->codigo, self::CODIGOS_APORTE_OBLIGATORIO, true)
        )?->base_utilizada ?? 0), 2);
        $totalAfp = round($aporteObligatorio + $primaSeguro + $comisionAfp, 2);
        $essalud = self::sumarConceptos($conceptos, self::CODIGOS_APORTACION_SALUD);

        return [
            'empresa' => $ciclo->empresa->nombre_comercial,
            'periodo' => $ciclo->nombre,
            'dni' => (string) $colaborador?->numero_documento,
            'nombre' => trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')),
            'tipo' => $boleta->regimen_laboral_snapshot === 'Locacion de Servicios' ? 'RH' : 'Planilla',
            'sueldo_bruto' => $sueldoBruto,
            'bonos' => round($totalIngresos - $sueldoBruto, 2),
            'base_afp' => $baseAfp,
            'aporte_obligatorio' => $aporteObligatorio,
            'prima_seguro' => $primaSeguro,
            'comision_afp' => $comisionAfp,
            'total_afp' => $totalAfp,
            // Todo egreso que no es AFP/ONP: tardanzas, faltas, adelantos,
            // renta de 5ta, descuentos operativos, etc., en una sola columna.
            'otros_descuentos' => round((float) $boleta->total_egresos - $totalAfp, 2),
            'neto' => round((float) $boleta->neto_a_pagar, 2),
            'essalud' => $essalud,
            'costo_empresa' => round($totalIngresos + $essalud, 2),
            'estado' => $boleta->estado === 'pagada' ? 'Pagado' : ucfirst((string) $boleta->estado),
        ];
    }

    /** @param  Collection<int, BoletaConcepto>  $conceptos */
    private static function sumarConceptos(Collection $conceptos, array $codigos): float
    {
        return round((float) $conceptos
            ->filter(fn (BoletaConcepto $c) => in_array($c->concepto?->codigo, $codigos, true))
            ->sum('monto'), 2);
    }

    /** @param  Collection<int, array<string, mixed>>  $filas */
    private static function llenarHojaDetalle(Worksheet $hoja, Collection $filas): void
    {
        $hoja->setTitle('Detalle_Planilla');

        $hoja->fromArray([
            'Empresa', 'Periodo', 'DNI', 'Colaborador', 'Tipo', 'Sueldo bruto', 'Bonos / HE',
            'Base AFP', 'AFP 10%', 'Prima seguro', 'Comisión AFP', 'Total AFP', 'Otros descuentos',
            'Neto a pagar', 'ESSALUD', 'Costo empresa', 'Estado',
        ], null, 'A1');

        foreach ($filas as $indice => $fila) {
            $numeroFila = $indice + 2;
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
            $hoja->setCellValue("N{$numeroFila}", $fila['neto']);
            $hoja->setCellValue("O{$numeroFila}", $fila['essalud']);
            $hoja->setCellValue("P{$numeroFila}", $fila['costo_empresa']);
            $hoja->setCellValue("Q{$numeroFila}", $fila['estado']);
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:Q1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);

        $ultimaFila = max(2, $filas->count() + 1);
        $hoja->getStyle("F2:P{$ultimaFila}")->getNumberFormat()->setFormatCode('S/ #,##0.00');
        $hoja->getStyle("C2:C{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A2');
        $hoja->setAutoFilter("A1:Q{$ultimaFila}");

        foreach ([
            'A' => 18, 'B' => 16, 'C' => 16, 'D' => 34, 'E' => 12,
            'F' => 16, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14,
            'K' => 14, 'L' => 14, 'M' => 16, 'N' => 16, 'O' => 14, 'P' => 16, 'Q' => 12,
        ] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    /** @param  Collection<int, array<string, mixed>>  $filas */
    private static function llenarHojaEjecutivo(Worksheet $hoja, CicloRemunerativo $ciclo, Collection $filas): void
    {
        $hoja->setTitle('Reporte_Ejecutivo');

        $totalBruto = round($filas->sum('sueldo_bruto') + $filas->sum('bonos'), 2);
        $totalAfp = round($filas->sum('total_afp'), 2);
        $totalNeto = round($filas->sum('neto'), 2);
        $totalEssalud = round($filas->sum('essalud'), 2);
        $totalCostoEmpresa = round($filas->sum('costo_empresa'), 2);
        $totalDescuentos = round($totalBruto - $totalNeto, 2);

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
            ['Empresa', $ciclo->empresa->nombre_comercial],
            ['Periodo', $ciclo->nombre],
            ['Fecha de reporte', now()->format('d/m/Y')],
            ['Responsable', 'RR. HH.'],
        ], null, 'A3');
        $hoja->getStyle('A3:A6')->getFont()->setBold(true);

        $hoja->fromArray(['Colaboradores', 'Bruto', 'Descuentos', 'Total AFP', 'Neto pagado', 'ESSALUD', 'Costo empresa'], null, 'A8');
        $hoja->fromArray([$filas->count(), $totalBruto, $totalDescuentos, $totalAfp, $totalNeto, $totalEssalud, $totalCostoEmpresa], null, 'A9');
        $hoja->getStyle('A8:G8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
        ]);
        $hoja->getStyle('B9:G9')->getNumberFormat()->setFormatCode('S/ #,##0.00');

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
        $hoja->getStyle('C13:C16')->getNumberFormat()->setFormatCode('S/ #,##0.00');

        foreach (['A' => 24, 'B' => 46, 'C' => 18, 'D' => 22] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }
}
