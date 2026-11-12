<?php

namespace App\Modules\Nominas\Infrastructure\PlanillaComplementaria\Export;

use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte de comisiones y horas extra registradas en las planillas
 * complementarias de un ciclo. Lee únicamente lo que ya quedó guardado en
 * `calculo_snapshot` (líneas agregadas a mano con `agregado_por`), sin
 * recalcular nada: lo que se ve aquí es lo que se registró.
 */
final class ComisionesHorasExtraExcelExporter
{
    private const TASAS = ['25' => '25%', '35' => '35%', '100' => '100%'];

    /**
     * @param  Collection<int, PlanillaComplementaria>  $items  Con `detalles.colaborador` precargado (ver PlanillaComplementariaService::listar()).
     */
    public static function generar(CicloRemunerativo $ciclo, Collection $items): string
    {
        $comisiones = [];
        $horasExtra = [];

        foreach ($items as $item) {
            foreach ($item->detalles as $detalle) {
                $colaborador = trim(($detalle->colaborador?->nombres ?? '').' '.($detalle->colaborador?->apellidos ?? ''));
                $documento = (string) $detalle->colaborador?->numero_documento;
                $snapshot = $detalle->calculo_snapshot ?? [];

                foreach ($snapshot['ingresos'] ?? [] as $linea) {
                    if (($linea['codigo'] ?? null) === 'COMISION' && isset($linea['agregado_por'])) {
                        $comisiones[] = [$item->nombre, $item->estado, $colaborador, $documento,
                            (float) ($linea['monto'] ?? 0), $linea['motivo'] ?? '', $linea['agregado_en'] ?? ''];
                    }
                }

                foreach ($snapshot['horas_extra_regularizadas'] ?? [] as $he) {
                    $minutos = (int) ($he['minutos'] ?? 0);
                    $horasExtra[] = [$item->nombre, $item->estado, $colaborador, $documento,
                        $he['fecha'] ?? '', self::TASAS[(string) ($he['tasa'] ?? '')] ?? (string) ($he['tasa'] ?? ''),
                        $minutos, round($minutos / 60, 2), (float) ($he['monto'] ?? 0),
                        ($he['origen'] ?? '') === 'detectada' ? 'Detectada por asistencia' : 'Manual', $he['motivo'] ?? ''];
                }
            }
        }

        $libro = new Spreadsheet;
        $hojaComisiones = $libro->getActiveSheet();
        self::llenarHoja($hojaComisiones, 'Comisiones', $ciclo, 'Total comisiones',
            ['Reintegro', 'Estado', 'Colaborador', 'DNI', 'Monto', 'Motivo', 'Registrado'],
            $comisiones, 'E', 'G', ['F']);

        $hojaHe = $libro->createSheet();
        self::llenarHoja($hojaHe, 'Horas extra', $ciclo, 'Total horas extra',
            ['Reintegro', 'Estado', 'Colaborador', 'DNI', 'Fecha', 'Tasa', 'Minutos', 'Horas', 'Monto', 'Origen', 'Motivo'],
            $horasExtra, 'I', 'K', ['K']);
        $hojaHe->getStyle('H5:H'.max(5, count($horasExtra) + 4))->getNumberFormat()->setFormatCode('0.00');

        $libro->setActiveSheetIndex(0);

        $flujo = fopen('php://temp', 'w+b');
        (new Xlsx($libro))->save($flujo);
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }

    /**
     * @param  array<int, string>  $encabezados
     * @param  array<int, array<int, mixed>>  $filas
     * @param  string  $columnaMonto  Columna con el monto (se suma y recibe formato S/).
     * @param  array<int, string>  $columnasAnchas  Columnas de texto libre (motivo).
     */
    private static function llenarHoja(Worksheet $hoja, string $titulo, CicloRemunerativo $ciclo, string $etiquetaTotal,
        array $encabezados, array $filas, string $columnaMonto, string $ultimaColumna, array $columnasAnchas): void
    {
        $hoja->setTitle($titulo);
        $total = array_sum(array_map(fn ($f) => $f[array_search($columnaMonto, range('A', 'Z'), true)], $filas));

        $hoja->fromArray(['Empresa', $ciclo->empresa->nombre_comercial, 'Ciclo', $ciclo->nombre,
            'Registros', count($filas), $etiquetaTotal, $total], null, 'A1');
        $hoja->fromArray($encabezados, null, 'A4');

        $dniColumna = 'D';
        foreach ($filas as $i => $fila) {
            $numero = $i + 5;
            foreach (array_values($fila) as $j => $valor) {
                $letra = chr(65 + $j);
                if ($letra === $dniColumna) {
                    $hoja->setCellValueExplicit("{$letra}{$numero}", (string) $valor, DataType::TYPE_STRING);
                } else {
                    $hoja->setCellValue("{$letra}{$numero}", $valor);
                }
            }
        }

        $estiloCabecera = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0B4F94']],
        ];
        $hoja->getStyle('A1:H1')->applyFromArray($estiloCabecera);
        $hoja->getStyle("A4:{$ultimaColumna}4")->applyFromArray($estiloCabecera);
        $hoja->getStyle('H1')->getNumberFormat()->setFormatCode('"S/" #,##0.00');

        $ultimaFila = max(5, count($filas) + 4);
        $hoja->getStyle("{$columnaMonto}5:{$columnaMonto}{$ultimaFila}")->getNumberFormat()->setFormatCode('"S/" #,##0.00');
        $hoja->getStyle("D5:D{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A5');
        $hoja->setAutoFilter("A4:{$ultimaColumna}{$ultimaFila}");

        foreach (range('A', $ultimaColumna) as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(match (true) {
                $columna === 'A' => 34, $columna === 'C' => 30, in_array($columna, $columnasAnchas, true) => 34, default => 16,
            });
        }
    }
}
