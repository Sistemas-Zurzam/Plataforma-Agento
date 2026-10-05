<?php

namespace App\Modules\Asistencia\Infrastructure;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Resumen de totales por colaborador (asistencia + horas extra) para que
 * RR.HH. lo envíe fuera del sistema a cada empresa y sus jefes/supervisores
 * lo corroboren y aprueben. Deliberadamente sin detalle diario -- eso ya se
 * revisa dentro de Agento (pestañas Asistencia diaria / Horas extra); esto
 * es el resumen para gente que no entra al sistema.
 */
final class ReporteColaboradoresExcelExporter
{
    /**
     * @param  Collection<int, Colaborador>  $colaboradores
     */
    public static function generar(Empresa $empresa, string $fechaDesde, string $fechaHasta, Collection $colaboradores): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Resumen asistencia');

        $hoja->fromArray([
            'Empresa', $empresa->nombre_comercial,
            'Período', "{$fechaDesde} a {$fechaHasta}",
        ], null, 'A1');

        $encabezados = [
            'DNI', 'Nombre y apellidos', 'Sede', 'Área', 'Cargo',
            'Días trabajados', 'Faltas', 'Tardanzas', 'Descansos/Feriados', 'Trabajo remoto',
            'Incidencias pendientes', 'Horas extra aprobadas', 'Horas extra pendientes de aprobación',
        ];
        $hoja->fromArray($encabezados, null, 'A3');

        foreach ($colaboradores as $indice => $colaborador) {
            $fila = $indice + 4;
            $resultados = $colaborador->resultadosAsistencia;
            $trabajados = $resultados->whereIn('estado', AsistenciaResultadoDiario::ESTADOS_CON_ASISTENCIA);
            $horasExtra = $colaborador->horasExtraAsistencia;

            $minutosAprobados = $horasExtra->where('estado', 'aprobado')->sum('minutos_aprobados');
            $minutosPendientes = $horasExtra->where('estado', 'pendiente')->sum('minutos_observados');

            $hoja->setCellValueExplicit("A{$fila}", (string) $colaborador->numero_documento, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", trim("{$colaborador->nombres} {$colaborador->apellidos}"));
            $hoja->setCellValue("C{$fila}", $colaborador->sede?->nombre ?? '');
            $hoja->setCellValue("D{$fila}", $colaborador->area?->nombre ?? '');
            $hoja->setCellValue("E{$fila}", $colaborador->cargo ?? '');
            $hoja->setCellValue("F{$fila}", $trabajados->count());
            $hoja->setCellValue("G{$fila}", $resultados->where('estado', 'falta')->count());
            $hoja->setCellValue("H{$fila}", $trabajados->where('minutos_tardanza', '>', 0)->count());
            $hoja->setCellValue("I{$fila}", $resultados->whereIn('tipo_dia', ['descanso', 'feriado'])->count());
            $hoja->setCellValue("J{$fila}", $resultados->where('estado', 'home_office')->count());
            $hoja->setCellValue("K{$fila}", $colaborador->incidenciasAsistencia->count());
            $hoja->setCellValue("L{$fila}", self::formatearDuracion($minutosAprobados));
            $hoja->setCellValue("M{$fila}", self::formatearDuracion($minutosPendientes));
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:B2')->applyFromArray([
            'font' => ['bold' => true],
        ]);
        $hoja->getStyle('A3:M3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $ultimaFila = max(4, $colaboradores->count() + 3);
        $hoja->getStyle("A4:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:M{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(20);
        $hoja->getRowDimension(2)->setRowHeight(20);

        foreach ([
            'A' => 14, 'B' => 34, 'C' => 18, 'D' => 20, 'E' => 22,
            'F' => 15, 'G' => 10, 'H' => 12, 'I' => 18, 'J' => 15,
            'K' => 20, 'L' => 20, 'M' => 26,
        ] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }

        $hoja->getHeaderFooter()->setOddHeader('&L'.$empresa->nombre_comercial.'&R'."{$fechaDesde} a {$fechaHasta}");

        $flujo = fopen('php://temp', 'w+b');
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

    private static function formatearDuracion(int $minutos): string
    {
        return sprintf('%dh %02dm', intdiv($minutos, 60), $minutos % 60);
    }
}
