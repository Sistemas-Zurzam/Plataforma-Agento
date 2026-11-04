<?php

namespace App\Modules\Nominas\Infrastructure\ConsumoComida;

use App\Modules\Nominas\Domain\ConsumoComida\ConsumoComidaFila;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Lee la hoja "Consumos" de la plantilla oficial (ver
 * ConsumoComidaPlantillaExporter). Solo parseo y controles estructurales: la
 * validación de negocio vive en ConsumoComidaImportacionService.
 *
 * Seguridad: el archivo debe ser un .xlsx real (paquete OpenXML), sin macros
 * (vbaProject.bin) ni vínculos externos. Se lee en modo solo datos, con un
 * filtro que limita filas y columnas, y nunca se calcula una fórmula: una
 * celda con fórmula se reporta como error de la fila.
 */
class ConsumoComidaXlsxReader
{
    public const HOJA = 'Consumos';

    public const MAXIMO_FILAS = 5000;

    private const COLUMNAS_LETRAS = ['A', 'B', 'C', 'D', 'E'];

    /**
     * @return list<array{fila: int, valores: array<string, mixed>, formulas: array<string, bool>, columna_adicional: bool}>
     *
     * @throws RuntimeException con un mensaje apto para el usuario
     */
    public function leer(string $ruta): array
    {
        $this->verificarPaquete($ruta);

        $lector = new Xlsx;
        $lector->setReadDataOnly(true);
        $lector->setReadEmptyCells(false);

        try {
            $hojas = collect($lector->listWorksheetInfo($ruta));
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el archivo: no es un Excel .xlsx válido.');
        }
        $hoja = $hojas->firstWhere('worksheetName', self::HOJA);
        if (! $hoja) {
            throw new RuntimeException('El archivo no tiene la hoja "'.self::HOJA.'". Usa la plantilla oficial.');
        }
        if ((int) $hoja['totalRows'] > self::MAXIMO_FILAS + 1) {
            throw new RuntimeException('El archivo supera el máximo de '.self::MAXIMO_FILAS.' filas de consumos.');
        }

        $lector->setLoadSheetsOnly(self::HOJA);
        $lector->setReadFilter(new class(self::MAXIMO_FILAS + 1) implements IReadFilter
        {
            public function __construct(private readonly int $maximoFila) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maximoFila && strlen($columnAddress) === 1 && $columnAddress <= 'F';
            }
        });

        try {
            $hojaCargada = $lector->load($ruta)->getSheetByName(self::HOJA);
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el archivo: no es un Excel .xlsx válido.');
        }
        if (! $hojaCargada) {
            throw new RuntimeException('El archivo no tiene la hoja "'.self::HOJA.'". Usa la plantilla oficial.');
        }

        $encabezados = [];
        foreach ([...self::COLUMNAS_LETRAS, 'F'] as $letra) {
            $encabezados[$letra] = mb_strtolower(trim((string) $hojaCargada->getCell("{$letra}1")->getValue()));
        }
        $esperados = array_combine(self::COLUMNAS_LETRAS, ConsumoComidaFila::COLUMNAS);
        foreach ($esperados as $letra => $nombre) {
            if ($encabezados[$letra] !== $nombre) {
                throw new RuntimeException('Encabezados inválidos: se esperaba "'.implode(', ', ConsumoComidaFila::COLUMNAS).'" en las columnas A a E, en ese orden.');
            }
        }
        if ($encabezados['F'] !== '') {
            throw new RuntimeException('La hoja "'.self::HOJA.'" tiene columnas adicionales después de "referencia".');
        }

        $filas = [];
        $ultimaFila = min($hojaCargada->getHighestDataRow(), self::MAXIMO_FILAS + 1);
        for ($numero = 2; $numero <= $ultimaFila; $numero++) {
            $valores = [];
            $formulas = [];
            $vacia = true;
            foreach ($esperados as $letra => $columna) {
                $celda = $hojaCargada->getCell("{$letra}{$numero}");
                $valor = $celda->getValue();
                $formulas[$columna] = $celda->getDataType() === DataType::TYPE_FORMULA;
                $valores[$columna] = $formulas[$columna] ? null : $valor;
                if ($formulas[$columna] || ($valor !== null && trim((string) $valor) !== '')) {
                    $vacia = false;
                }
            }
            $columnaAdicional = $hojaCargada->getCell("F{$numero}")->getValue() !== null;
            if ($vacia && ! $columnaAdicional) {
                continue; // fila en blanco: no es un consumo
            }
            $filas[] = ['fila' => $numero, 'valores' => $valores, 'formulas' => $formulas, 'columna_adicional' => $columnaAdicional];
        }

        if ($filas === []) {
            throw new RuntimeException('La hoja "'.self::HOJA.'" no contiene consumos.');
        }

        return $filas;
    }

    private function verificarPaquete(string $ruta): void
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El archivo no es un Excel .xlsx válido.');
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('El archivo no es un Excel .xlsx válido.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = strtolower((string) $zip->getNameIndex($i));
                if (str_contains($nombre, 'vbaproject') || str_contains($nombre, 'activex')) {
                    throw new RuntimeException('El archivo contiene macros o controles activos; guarda la plantilla como .xlsx sin macros.');
                }
                if (str_starts_with($nombre, 'xl/externallinks/')) {
                    throw new RuntimeException('El archivo contiene vínculos a otros libros; copia los valores y vuelve a guardarlo.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
