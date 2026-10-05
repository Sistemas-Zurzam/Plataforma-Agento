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
        $hoja->setCellValue('A2', 'Días registrados = Trabajados + Faltas + Permisos/F. justificadas + Descansos/Feriados + Trabajo remoto + Sin clasificar. "Días con tardanza" ya está incluido dentro de Días trabajados: no se suma.');

        $encabezados = [
            'DNI', 'Nombre y apellidos', 'Sede', 'Área', 'Cargo',
            'Días registrados', 'Días trabajados', 'Días con tardanza (incl. en trabajados)', 'Faltas',
            'Permisos / F. justificadas', 'Descansos/Feriados', 'Trabajo remoto', 'Sin clasificar',
            'Incidencias pendientes', 'Horas extra aprobadas', 'Horas extra pendientes de aprobación',
        ];
        $hoja->fromArray($encabezados, null, 'A3');

        foreach ($colaboradores as $indice => $colaborador) {
            $fila = $indice + 4;
            $resultados = $colaborador->resultadosAsistencia;
            $horasExtra = $colaborador->horasExtraAsistencia;

            // Cada día cae en una sola columna, según su estado real (no el
            // tipo de día planificado): trabajar en un descanso cuenta como
            // trabajado, nunca también como descanso.
            $trabajados = $resultados->whereIn('estado', AsistenciaResultadoDiario::ESTADOS_CON_ASISTENCIA);
            $faltas = $resultados->where('estado', 'falta')->count();
            $justificados = $resultados->whereIn('estado', ['permiso', 'falta_justificada'])->count();
            $descansos = $resultados->whereIn('estado', ['descanso', 'feriado'])->count();
            $remoto = $resultados->where('estado', 'home_office')->count();
            $sinClasificar = $resultados->count() - $trabajados->count() - $faltas - $justificados - $descansos - $remoto;

            $minutosAprobados = $horasExtra->where('estado', 'aprobado')->sum('minutos_aprobados');
            $minutosPendientes = $horasExtra->where('estado', 'pendiente')->sum('minutos_observados');

            $hoja->setCellValueExplicit("A{$fila}", (string) $colaborador->numero_documento, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", trim("{$colaborador->nombres} {$colaborador->apellidos}"));
            $hoja->setCellValue("C{$fila}", $colaborador->sede?->nombre ?? '');
            $hoja->setCellValue("D{$fila}", $colaborador->area?->nombre ?? '');
            $hoja->setCellValue("E{$fila}", $colaborador->cargo ?? '');
            $hoja->setCellValue("F{$fila}", $resultados->count());
            $hoja->setCellValue("G{$fila}", $trabajados->count());
            $hoja->setCellValue("H{$fila}", $trabajados->where('minutos_tardanza', '>', 0)->count());
            $hoja->setCellValue("I{$fila}", $faltas);
            $hoja->setCellValue("J{$fila}", $justificados);
            $hoja->setCellValue("K{$fila}", $descansos);
            $hoja->setCellValue("L{$fila}", $remoto);
            $hoja->setCellValue("M{$fila}", $sinClasificar);
            $hoja->setCellValue("N{$fila}", $colaborador->incidenciasAsistencia->count());
            $hoja->setCellValue("O{$fila}", self::formatearDuracion($minutosAprobados));
            $hoja->setCellValue("P{$fila}", self::formatearDuracion($minutosPendientes));
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true],
        ]);
        $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF555555');
        $hoja->getStyle('A3:P3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        // "Días con tardanza" es un subconjunto de trabajados: se distingue
        // visualmente para que no se lea como otra categoría a sumar.
        $hoja->getStyle('H3')->getFill()->getStartColor()->setARGB('FF5B7FB0');

        $ultimaFila = max(4, $colaboradores->count() + 3);
        $hoja->getStyle("A4:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle("H4:H{$ultimaFila}")->getFont()->setItalic(true);
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:P{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(20);
        $hoja->getRowDimension(2)->setRowHeight(20);
        $hoja->getRowDimension(3)->setRowHeight(32);

        foreach ([
            'A' => 14, 'B' => 34, 'C' => 18, 'D' => 20, 'E' => 22,
            'F' => 12, 'G' => 12, 'H' => 16, 'I' => 9, 'J' => 14,
            'K' => 13, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 15, 'P' => 18,
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
