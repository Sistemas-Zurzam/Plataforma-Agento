<?php

namespace App\Modules\Asistencia\Infrastructure;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Detalle de horas extra con las mismas columnas que la tabla de la pestaña
 * "Horas extra" — pensado para armar el reporte por colaborador que se
 * entrega a la jefatura.
 */
final class HorasExtraExcelExporter
{
    private const COLORES_ESTADO = [
        AsistenciaHoraExtra::ESTADO_PENDIENTE => 'FFFFF4CC',
        AsistenciaHoraExtra::ESTADO_APROBADO => 'FFDDF4E4',
        AsistenciaHoraExtra::ESTADO_RECHAZADO => 'FFFBE0E0',
    ];

    /**
     * $montos (id => ['monto' => ?float, ...]) lo entrega Nóminas solo a
     * quien puede ver importes; sin él no se agrega la columna de monto.
     *
     * @param  Collection<int, AsistenciaHoraExtra>  $horasExtra
     * @param  array<int, array{monto: ?float}>|null  $montos
     */
    public static function generar(Empresa $empresa, string $fechaDesde, string $fechaHasta, Collection $horasExtra, ?array $montos = null): string
    {
        $conMonto = $montos !== null;
        // Con monto, la columna va entre "Aprobadas" y "Estado" (igual que la pantalla).
        [$colEstado, $colMotivo, $ultima] = $conMonto ? ['G', 'H', 'H'] : ['F', 'G', 'G'];

        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Horas extra');

        $hoja->fromArray(['Empresa', $empresa->nombre_comercial], null, 'A1');
        $hoja->fromArray(['Período', "{$fechaDesde} a {$fechaHasta}"], null, 'A2');

        $hoja->fromArray([
            'Fecha', 'Colaborador', 'Tasa', 'Observadas', 'Aprobadas',
            ...($conMonto ? ['Monto aprox. (S/)'] : []),
            'Estado', 'Motivo',
        ], null, 'A4');

        $fila = 5;
        foreach ($horasExtra as $registro) {
            $colaborador = $registro->colaborador;

            $hoja->setCellValue("A{$fila}", $registro->fecha?->format('d/m/Y'));
            $hoja->setCellValue("B{$fila}", trim(($colaborador?->nombres ?? '').' '.($colaborador?->apellidos ?? '')));
            $hoja->setCellValue("C{$fila}", "{$registro->tasa}%");
            $hoja->setCellValue("D{$fila}", self::formatearDuracion((int) $registro->minutos_observados));
            $hoja->setCellValue("E{$fila}", self::formatearDuracion((int) $registro->minutos_aprobados));
            if ($conMonto) {
                $monto = $montos[$registro->id]['monto'] ?? null;
                $hoja->setCellValue("F{$fila}", $monto ?? '—');
            }
            $hoja->setCellValue("{$colEstado}{$fila}", $registro->estado);
            $hoja->setCellValue("{$colMotivo}{$fila}", $registro->motivo ?: '—');

            if ($color = self::COLORES_ESTADO[$registro->estado] ?? null) {
                $hoja->getStyle("{$colEstado}{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($color);
            }
            $fila++;
        }

        $hoja->setCellValue("C{$fila}", 'Total');
        $hoja->setCellValue("D{$fila}", self::formatearDuracion((int) $horasExtra->sum('minutos_observados')));
        $hoja->setCellValue("E{$fila}", self::formatearDuracion((int) $horasExtra->sum('minutos_aprobados')));
        if ($conMonto) {
            // Las rechazadas no se pagan: no suman al total referencial.
            $hoja->setCellValue("F{$fila}", round($horasExtra
                ->reject(fn (AsistenciaHoraExtra $registro) => $registro->estado === AsistenciaHoraExtra::ESTADO_RECHAZADO)
                ->sum(fn (AsistenciaHoraExtra $registro) => (float) ($montos[$registro->id]['monto'] ?? 0)), 2));
            $hoja->getStyle('F5:F'.$fila)->getNumberFormat()->setFormatCode('#,##0.00');
        }
        $hoja->getStyle("C{$fila}:".($conMonto ? 'F' : 'E').$fila)->getFont()->setBold(true);

        $hoja->getStyle('A1:A2')->getFont()->setBold(true);
        $hoja->getStyle("A4:{$ultima}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0B4F94']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->freezePane('A5');
        $hoja->setAutoFilter("A4:{$ultima}".max(5, $fila - 1));

        $anchos = ['A' => 12, 'B' => 38, 'C' => 8, 'D' => 13, 'E' => 13]
            + ($conMonto ? ['F' => 16, 'G' => 13, 'H' => 40] : ['F' => 13, 'G' => 40]);
        foreach ($anchos as $columna => $ancho) {
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

    private static function formatearDuracion(int $minutos): string
    {
        return sprintf('%dh %02dm', intdiv($minutos, 60), $minutos % 60);
    }
}
