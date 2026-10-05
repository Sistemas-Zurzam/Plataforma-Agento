<?php

namespace App\Modules\Asistencia\Infrastructure;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
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
        $hoja->setCellValue('A2', 'Se lee como una suma: Trabajados + Faltas + En revisión + Permisos + Descansos + Remoto + Sin clasificar + Sin registro = Total días. "En revisión": días del 27 al cierre sin marcación, no se consideran falta (igual que en la planilla). "Con tardanza" es solo un detalle de los días trabajados: no se suma.');

        $encabezados = [
            'DNI', 'Nombre y apellidos', 'Sede', 'Área', 'Cargo',
            'Días trabajados', 'Faltas', 'En revisión (27 al cierre, no es falta)', 'Permisos / F. justificadas', 'Descansos / Feriados',
            'Trabajo remoto', 'Sin clasificar', 'Sin registro', '= Total días',
            'De los trabajados: con tardanza', 'Incidencias pendientes', 'Horas extra aprobadas', 'Horas extra pendientes de aprobación',
        ];
        $hoja->fromArray($encabezados, null, 'A3');

        // Todo como fechas calendario en el mismo timezone, para que
        // diffInDays no reciba horas sueltas por la diferencia con Lima.
        $hoy = Carbon::parse(app(FechaOperativa::class)->hoy()->toDateString());
        $inicioPeriodo = Carbon::parse($fechaDesde)->startOfDay();
        $finPeriodo = Carbon::parse($fechaHasta)->startOfDay()->min($hoy);

        foreach ($colaboradores as $indice => $colaborador) {
            $fila = $indice + 4;
            $resultados = $colaborador->resultadosAsistencia;
            $horasExtra = $colaborador->horasExtraAsistencia;

            // Cada día cae en una sola columna, según su estado real (no el
            // tipo de día planificado): trabajar en un descanso cuenta como
            // trabajado, nunca también como descanso.
            $trabajados = $resultados->whereIn('estado', AsistenciaResultadoDiario::ESTADOS_CON_ASISTENCIA);
            $enRevision = $resultados->filter(fn (AsistenciaResultadoDiario $r) => $r->esFaltaEnRevision())->count();
            $faltas = $resultados->where('estado', 'falta')->count() - $enRevision;
            $justificados = $resultados->whereIn('estado', ['permiso', 'falta_justificada'])->count();
            $descansos = $resultados->whereIn('estado', ['descanso', 'feriado'])->count();
            $remoto = $resultados->where('estado', 'home_office')->count();
            $sinClasificar = $resultados->count() - $trabajados->count() - $faltas - $enRevision - $justificados - $descansos - $remoto;
            [$sinRegistro, $sinRegistroEnRevision] = self::diasSinRegistro($colaborador, $inicioPeriodo, $finPeriodo, $resultados);
            $enRevision += $sinRegistroEnRevision;

            $minutosAprobados = $horasExtra->where('estado', 'aprobado')->sum('minutos_aprobados');
            $minutosPendientes = $horasExtra->where('estado', 'pendiente')->sum('minutos_observados');

            $hoja->setCellValueExplicit("A{$fila}", (string) $colaborador->numero_documento, DataType::TYPE_STRING);
            $hoja->setCellValue("B{$fila}", trim("{$colaborador->nombres} {$colaborador->apellidos}"));
            $hoja->setCellValue("C{$fila}", $colaborador->sede?->nombre ?? '');
            $hoja->setCellValue("D{$fila}", $colaborador->area?->nombre ?? '');
            $hoja->setCellValue("E{$fila}", $colaborador->cargo ?? '');
            $hoja->setCellValue("F{$fila}", $trabajados->count());
            $hoja->setCellValue("G{$fila}", $faltas);
            $hoja->setCellValue("H{$fila}", $enRevision);
            $hoja->setCellValue("I{$fila}", $justificados);
            $hoja->setCellValue("J{$fila}", $descansos);
            $hoja->setCellValue("K{$fila}", $remoto);
            $hoja->setCellValue("L{$fila}", $sinClasificar);
            $hoja->setCellValue("M{$fila}", $sinRegistro);
            // Fórmula visible: el total se puede auditar en el propio Excel.
            $hoja->setCellValue("N{$fila}", "=SUM(F{$fila}:M{$fila})");
            $hoja->setCellValue("O{$fila}", $trabajados->where('minutos_tardanza', '>', 0)->count());
            $hoja->setCellValue("P{$fila}", $colaborador->incidenciasAsistencia->count());
            $hoja->setCellValue("Q{$fila}", self::formatearDuracion($minutosAprobados));
            $hoja->setCellValue("R{$fila}", self::formatearDuracion($minutosPendientes));
        }

        $azul = '0B4F94';
        $hoja->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true],
        ]);
        $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF555555');
        $hoja->getStyle('A3:R3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$azul]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        // El total y el detalle de tardanzas se distinguen de las columnas
        // que se suman, para que no se lean como otra categoría.
        $hoja->getStyle('N3')->getFill()->getStartColor()->setARGB('FF06325F');
        $hoja->getStyle('O3')->getFill()->getStartColor()->setARGB('FF5B7FB0');

        $ultimaFila = max(4, $colaboradores->count() + 3);
        $hoja->getStyle("A4:A{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->getStyle("N4:N{$ultimaFila}")->getFont()->setBold(true);
        $hoja->getStyle("N4:N{$ultimaFila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EEF6');
        $hoja->getStyle("O4:O{$ultimaFila}")->getFont()->setItalic(true);
        $hoja->freezePane('A4');
        $hoja->setAutoFilter("A3:R{$ultimaFila}");
        $hoja->getRowDimension(1)->setRowHeight(20);
        $hoja->getRowDimension(2)->setRowHeight(20);
        $hoja->getRowDimension(3)->setRowHeight(44);

        foreach ([
            'A' => 14, 'B' => 34, 'C' => 18, 'D' => 20, 'E' => 22,
            'F' => 11, 'G' => 8, 'H' => 14, 'I' => 13, 'J' => 12, 'K' => 10,
            'L' => 11, 'M' => 10, 'N' => 11, 'O' => 15, 'P' => 13, 'Q' => 14, 'R' => 18,
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

    /**
     * Días del período con vínculo (ingreso a cese, sin días futuros) que no
     * tienen ningún resultado. Los que caen del 27 al cierre se devuelven
     * aparte: están en revisión, no son faltas ni datos faltantes.
     *
     * @return array{0: int, 1: int} [sin registro, sin registro en revisión]
     */
    private static function diasSinRegistro(Colaborador $colaborador, Carbon $inicio, Carbon $fin, Collection $resultados): array
    {
        $ingreso = $colaborador->fecha_ingreso ? Carbon::parse($colaborador->fecha_ingreso->toDateString()) : null;
        $cese = $colaborador->fecha_cese ? Carbon::parse($colaborador->fecha_cese->toDateString()) : null;
        $desde = $ingreso?->greaterThan($inicio) ? $ingreso : $inicio->copy();
        $hasta = $cese?->lessThan($fin) ? $cese : $fin->copy();

        $registradas = $resultados->map(fn (AsistenciaResultadoDiario $r) => $r->fecha->toDateString())->flip();
        $sinRegistro = 0;
        $enRevision = 0;
        for ($fecha = $desde->copy(); $fecha->lte($hasta); $fecha->addDay()) {
            if ($registradas->has($fecha->toDateString())) {
                continue;
            }
            $fecha->day >= AsistenciaResultadoDiario::DIA_INICIO_REVISION ? $enRevision++ : $sinRegistro++;
        }

        return [$sinRegistro, $enRevision];
    }

    private static function formatearDuracion(int $minutos): string
    {
        return sprintf('%dh %02dm', intdiv($minutos, 60), $minutos % 60);
    }
}
