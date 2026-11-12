<?php

namespace App\Modules\Nominas\Infrastructure\PlanillaComplementaria\Export;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reporte de comisiones y horas extra registradas en las planillas
 * complementarias de uno o varios ciclos (una fila por línea, con su ciclo).
 * Lee únicamente lo que ya quedó guardado en
 * `calculo_snapshot` (líneas agregadas a mano con `agregado_por`), sin
 * recalcular nada: lo que se ve aquí es lo que se registró.
 */
final class ComisionesHorasExtraExcelExporter
{
    private const TASAS = ['25' => '25%', '35' => '35%', '100' => '100%'];

    /**
     * @param  string  $periodo  Etiqueta del periodo exportado (ciclo o rango de meses).
     * @param  Collection<int, PlanillaComplementaria>  $items  Con `ciclo` y `detalles.colaborador` precargados, en el orden de salida.
     */
    public static function generar(Empresa $empresa, string $periodo, Collection $items): string
    {
        $comisiones = [];
        $horasExtra = [];

        foreach ($items as $item) {
            foreach ($item->detalles as $detalle) {
                $colaborador = trim(($detalle->colaborador?->nombres ?? '').' '.($detalle->colaborador?->apellidos ?? ''));
                $documento = (string) $detalle->colaborador?->numero_documento;
                $snapshot = $detalle->calculo_snapshot ?? [];
                $ciclo = $item->ciclo->nombre;

                foreach ($snapshot['ingresos'] ?? [] as $linea) {
                    if (($linea['codigo'] ?? null) === 'COMISION' && isset($linea['agregado_por'])) {
                        $comisiones[] = [$ciclo, $item->nombre, $item->estado, $colaborador, $documento,
                            (float) ($linea['monto'] ?? 0), $linea['motivo'] ?? '', $linea['agregado_en'] ?? ''];
                    }
                }

                foreach ($snapshot['horas_extra_regularizadas'] ?? [] as $he) {
                    $minutos = (int) ($he['minutos'] ?? 0);
                    $horasExtra[] = [$ciclo, $item->nombre, $item->estado, $colaborador, $documento,
                        $he['fecha'] ?? '', self::TASAS[(string) ($he['tasa'] ?? '')] ?? (string) ($he['tasa'] ?? ''),
                        $minutos, round($minutos / 60, 2), (float) ($he['monto'] ?? 0),
                        ($he['origen'] ?? '') === 'detectada' ? 'Detectada por asistencia' : 'Manual', $he['motivo'] ?? ''];
                }
            }
        }

        $libro = new Spreadsheet;
        $hojaComisiones = $libro->getActiveSheet();
        self::llenarHoja($hojaComisiones, 'Comisiones', $empresa, $periodo, 'Total comisiones',
            ['Ciclo', 'Reintegro', 'Estado', 'Colaborador', 'DNI', 'Monto', 'Motivo', 'Registrado'],
            $comisiones, 'F', 'H', ['G']);

        $hojaHe = $libro->createSheet();
        self::llenarHoja($hojaHe, 'Horas extra', $empresa, $periodo, 'Total horas extra',
            ['Ciclo', 'Reintegro', 'Estado', 'Colaborador', 'DNI', 'Fecha', 'Tasa', 'Minutos', 'Horas', 'Monto', 'Origen', 'Motivo'],
            $horasExtra, 'J', 'L', ['L']);
        $hojaHe->getStyle('I5:I'.max(5, count($horasExtra) + 4))->getNumberFormat()->setFormatCode('0.00');

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
    private static function llenarHoja(Worksheet $hoja, string $titulo, Empresa $empresa, string $periodo, string $etiquetaTotal,
        array $encabezados, array $filas, string $columnaMonto, string $ultimaColumna, array $columnasAnchas): void
    {
        $hoja->setTitle($titulo);
        $total = array_sum(array_map(fn ($f) => $f[array_search($columnaMonto, range('A', 'Z'), true)], $filas));

        $hoja->fromArray(['Empresa', $empresa->nombre_comercial, 'Periodo', $periodo,
            'Registros', count($filas), $etiquetaTotal, $total], null, 'A1');
        $hoja->fromArray($encabezados, null, 'A4');

        $dniColumna = 'E';
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
        $hoja->getStyle("E5:E{$ultimaFila}")->getNumberFormat()->setFormatCode('@');
        $hoja->freezePane('A5');
        $hoja->setAutoFilter("A4:{$ultimaColumna}{$ultimaFila}");

        foreach (range('A', $ultimaColumna) as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(match (true) {
                $columna === 'A' => 22, $columna === 'B' => 34, $columna === 'D' => 30, in_array($columna, $columnasAnchas, true) => 34, default => 16,
            });
        }
    }
}
