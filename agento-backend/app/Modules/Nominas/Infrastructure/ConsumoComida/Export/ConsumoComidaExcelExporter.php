<?php

namespace App\Modules\Nominas\Infrastructure\ConsumoComida\Export;

use App\Modules\Nominas\Domain\ConsumoComida\ConsumoComidaFila;
use App\Modules\Nominas\Infrastructure\ConsumoComida\ConsumoComidaXlsxReader;
use App\Modules\Nominas\Models\ConsumoComidaImportacion;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Plantilla oficial de consumos de comida y reporte de errores de una
 * importación. Todo texto proveniente de usuarios se escribe como celda de
 * texto explícita y, si empieza con un carácter que una hoja de cálculo
 * interpretaría como fórmula (= + - @), se antepone un apóstrofo.
 */
final class ConsumoComidaExcelExporter
{
    private const AZUL = 'FF0B4F94';

    // Formato de texto/fecha aplicado a las primeras filas de la plantilla.
    private const FILAS_CON_FORMATO = 2001;

    public static function plantilla(): string
    {
        $libro = new Spreadsheet;

        $consumos = $libro->getActiveSheet();
        $consumos->setTitle(ConsumoComidaXlsxReader::HOJA);
        $consumos->fromArray(ConsumoComidaFila::COLUMNAS, null, 'A1');
        self::estilarEncabezado($consumos, 'A1:E1');
        // Documento y referencia como texto: conserva los ceros iniciales.
        $consumos->getStyle('A2:A'.self::FILAS_CON_FORMATO)->getNumberFormat()->setFormatCode('@');
        $consumos->getStyle('E2:E'.self::FILAS_CON_FORMATO)->getNumberFormat()->setFormatCode('@');
        $consumos->getStyle('B2:B'.self::FILAS_CON_FORMATO)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $consumos->getStyle('D2:D'.self::FILAS_CON_FORMATO)->getNumberFormat()->setFormatCode('0.00');
        foreach (['A' => 16, 'B' => 16, 'C' => 32, 'D' => 12, 'E' => 24] as $columna => $ancho) {
            $consumos->getColumnDimension($columna)->setWidth($ancho);
        }
        $consumos->freezePane('A2');

        $ejemplos = $libro->createSheet();
        $ejemplos->setTitle('Ejemplos');
        $ejemplos->fromArray(ConsumoComidaFila::COLUMNAS, null, 'A1');
        self::estilarEncabezado($ejemplos, 'A1:E1');
        $filasEjemplo = [
            ['00123456', '2026-10-05', 'Almuerzo', '12.50', 'TICKET-0001'],
            ['00123456', '2026-10-06', 'Almuerzo', '12.50', 'TICKET-0002'],
            ['71234567', '2026-10-05', 'Desayuno', '6.00', ''],
        ];
        foreach ($filasEjemplo as $indice => $fila) {
            foreach (['A', 'B', 'C', 'D', 'E'] as $posicion => $letra) {
                $ejemplos->setCellValueExplicit($letra.($indice + 2), $fila[$posicion], DataType::TYPE_STRING);
            }
        }
        foreach (['A' => 16, 'B' => 16, 'C' => 32, 'D' => 12, 'E' => 24] as $columna => $ancho) {
            $ejemplos->getColumnDimension($columna)->setWidth($ancho);
        }

        $instrucciones = $libro->createSheet();
        $instrucciones->setTitle('Instrucciones');
        $lineas = [
            ['Plantilla de consumos de comida — Agento'],
            [''],
            ['1. Registra los consumos en la hoja "Consumos": una fila por consumo. No cambies los encabezados ni su orden.'],
            ['2. documento (obligatorio): número de documento del colaborador, como texto. Conserva los ceros iniciales (ej. 00123456).'],
            ['3. fecha_consumo (obligatorio): fecha del consumo con formato AAAA-MM-DD (ej. 2026-10-05) o una celda con formato de fecha. Debe estar dentro del período del ciclo.'],
            ['4. concepto (obligatorio): descripción del consumo (ej. Almuerzo, Desayuno).'],
            ['5. monto (obligatorio): importe positivo con punto decimal y máximo dos decimales (ej. 12.50).'],
            ['6. referencia (opcional): identificador único del consumo en el sistema de origen (ticket, voucher). Evita que el mismo consumo se descuente dos veces.'],
            ['7. Un colaborador puede tener varias filas: su descuento es la suma de sus consumos válidos del ciclo.'],
            ['8. No uses fórmulas, macros ni vínculos a otros libros. Máximo '.ConsumoComidaXlsxReader::MAXIMO_FILAS.' consumos por archivo y 10 MB.'],
            ['9. La hoja "Ejemplos" es solo de referencia: no se importa.'],
        ];
        $instrucciones->fromArray($lineas, null, 'A1');
        $instrucciones->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $instrucciones->getColumnDimension('A')->setWidth(130);

        $libro->setActiveSheetIndex(0);

        return self::guardar($libro);
    }

    public static function errores(ConsumoComidaImportacion $importacion): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Errores');
        $encabezados = ['fila', 'documento', 'colaborador', 'fecha_consumo', 'concepto', 'monto', 'referencia', 'estado', 'errores'];
        $hoja->fromArray($encabezados, null, 'A1');
        self::estilarEncabezado($hoja, 'A1:I1');

        $fila = 2;
        foreach ($importacion->errores_generales ?? [] as $error) {
            $hoja->setCellValueExplicit("H{$fila}", 'error_general', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit("I{$fila}", self::textoSeguro($error), DataType::TYPE_STRING);
            $fila++;
        }

        $detalles = $importacion->detalles()->where('estado', '!=', 'valido')->orderBy('fila')->get();
        foreach ($detalles as $detalle) {
            $original = $detalle->valores_originales ?? [];
            $valores = [
                'A' => (string) $detalle->fila,
                'B' => $detalle->documento ?? (string) ($original['documento'] ?? ''),
                'C' => (string) ($detalle->colaborador_nombre_snapshot ?? ''),
                'D' => $detalle->fecha_consumo?->format('Y-m-d') ?? (string) ($original['fecha_consumo'] ?? ''),
                'E' => $detalle->concepto ?? (string) ($original['concepto'] ?? ''),
                'F' => $detalle->monto !== null ? (string) $detalle->monto : (string) ($original['monto'] ?? ''),
                'G' => $detalle->referencia ?? (string) ($original['referencia'] ?? ''),
                'H' => $detalle->estado,
                'I' => implode(' | ', $detalle->errores ?? []),
            ];
            foreach ($valores as $letra => $valor) {
                $hoja->setCellValueExplicit("{$letra}{$fila}", self::textoSeguro($valor), DataType::TYPE_STRING);
            }
            $fila++;
        }

        foreach (['A' => 8, 'B' => 16, 'C' => 32, 'D' => 14, 'E' => 28, 'F' => 12, 'G' => 22, 'H' => 18, 'I' => 80] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
        $hoja->freezePane('A2');

        return self::guardar($libro);
    }

    /** Evita que un valor importado se interprete como fórmula al abrir el reporte. */
    public static function textoSeguro(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'".$valor : $valor;
    }

    private static function estilarEncabezado(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::AZUL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private static function guardar(Spreadsheet $libro): string
    {
        $flujo = fopen('php://temp', 'w+b');
        (new Xlsx($libro))->save($flujo);
        rewind($flujo);
        $contenido = stream_get_contents($flujo);
        fclose($flujo);
        $libro->disconnectWorksheets();

        return $contenido === false ? '' : $contenido;
    }
}
