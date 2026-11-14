<?php

namespace App\Modules\Nominas\Infrastructure\Cts;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Lee el Excel de cálculo de CTS de RR.HH. (una fila por trabajador) para
 * cargarlo como CTS histórica. Las columnas se ubican por su encabezado, no
 * por su posición. Las celdas con fórmula se leen con el valor que Excel
 * guardó al calcular (nunca se evalúan fórmulas del archivo).
 *
 * Seguridad: solo .xlsx real, sin macros ni vínculos a otros libros.
 */
class CtsExcelHistoricoReader
{
    public const MAXIMO_FILAS = 2000;

    private const CAMPOS = ['documento', 'sueldo', 'dias', 'gratificacion', 'sexto', 'horas_extra', 'comisiones', 'base', 'cts_100', 'depositar'];

    public const REQUERIDAS = ['documento', 'sueldo', 'dias', 'gratificacion', 'horas_extra', 'comisiones', 'depositar'];

    private const NOMBRES = [
        'documento' => 'Documento', 'sueldo' => 'Sueldo base', 'dias' => 'Días computables', 'gratificacion' => 'Gratificación',
        'horas_extra' => 'Promedio de horas extra', 'comisiones' => 'Promedio de comisiones', 'depositar' => 'CTS a depositar',
    ];

    /**
     * @return array{hoja: string, filas: list<array{fila: int, documento: string, valores: array<string, ?float>, invalidos: list<string>}>}
     *
     * @throws RuntimeException con un mensaje apto para el usuario
     */
    public function leer(string $ruta): array
    {
        $this->verificarPaquete($ruta);

        $lector = new Xlsx;
        $lector->setReadDataOnly(true);
        try {
            $libro = $lector->load($ruta);
        } catch (Throwable) {
            throw new RuntimeException('No se pudo leer el archivo: no es un Excel .xlsx válido.');
        }

        foreach ($libro->getWorksheetIterator() as $hoja) {
            $encabezado = $this->encabezado($hoja);
            if ($encabezado) {
                return ['hoja' => $hoja->getTitle(), 'filas' => $this->filas($hoja, ...$encabezado)];
            }
        }

        throw new RuntimeException('No se encontró la fila de encabezados. El Excel debe tener las columnas: '.implode(', ', array_map(fn ($c) => self::NOMBRES[$c], self::REQUERIDAS)).'.');
    }

    /** @return array{0: int, 1: array<string, string>}|null Fila de encabezados y columna (letra) de cada campo. */
    private function encabezado(Worksheet $hoja): ?array
    {
        $ultimaColumna = min(Coordinate::columnIndexFromString($hoja->getHighestDataColumn()), 40);
        for ($fila = 1; $fila <= min(30, $hoja->getHighestDataRow()); $fila++) {
            $columnas = [];
            for ($i = 1; $i <= $ultimaColumna; $i++) {
                $letra = Coordinate::stringFromColumnIndex($i);
                $texto = self::normalizar((string) $hoja->getCell($letra.$fila)->getValue());
                if ($texto === '') {
                    continue;
                }
                $campo = self::campo($texto);
                if ($campo && ! isset($columnas[$campo])) {
                    $columnas[$campo] = $letra;
                }
            }
            if (isset($columnas['documento'])) {
                $faltan = array_diff(self::REQUERIDAS, array_keys($columnas));
                if ($faltan) {
                    throw new RuntimeException('Faltan columnas en el Excel: '.implode(', ', array_map(fn ($c) => self::NOMBRES[$c], $faltan)).'.');
                }

                return [$fila, $columnas];
            }
        }

        return null;
    }

    /** @param  array<string, string>  $columnas */
    private function filas(Worksheet $hoja, int $filaEncabezado, array $columnas): array
    {
        $filas = [];
        $ultima = min($hoja->getHighestDataRow(), $filaEncabezado + self::MAXIMO_FILAS);
        for ($numero = $filaEncabezado + 1; $numero <= $ultima; $numero++) {
            $documento = self::documento($this->valor($hoja, $columnas['documento'].$numero));
            if ($documento === '') {
                continue; // fila de total o en blanco
            }
            $valores = [];
            $invalidos = [];
            foreach (self::CAMPOS as $campo) {
                if ($campo === 'documento') {
                    continue;
                }
                if (! isset($columnas[$campo])) {
                    $valores[$campo] = null;

                    continue;
                }
                $crudo = $this->valor($hoja, $columnas[$campo].$numero);
                $numeroLeido = self::numero($crudo);
                if ($numeroLeido === false) {
                    $invalidos[] = $campo;
                    $numeroLeido = null;
                }
                $valores[$campo] = $numeroLeido;
            }
            $filas[] = ['fila' => $numero, 'documento' => $documento, 'valores' => $valores, 'invalidos' => $invalidos];
        }

        if ($filas === []) {
            throw new RuntimeException('El Excel no tiene filas con documento debajo de los encabezados.');
        }

        return $filas;
    }

    /** Valor de la celda; si es fórmula, el resultado que Excel guardó (no se evalúa). */
    private function valor(Worksheet $hoja, string $coordenada): mixed
    {
        $celda = $hoja->getCell($coordenada);

        return $celda->getDataType() === DataType::TYPE_FORMULA ? $celda->getOldCalculatedValue() : $celda->getValue();
    }

    /** Campo que representa un encabezado (texto normalizado: minúsculas, sin tildes). */
    private static function campo(string $h): ?string
    {
        return match (true) {
            $h === 'documento', $h === 'dni', str_starts_with($h, 'numero de documento') => 'documento',
            str_contains($h, 'sueldo'), $h === 'remuneracion basica' => 'sueldo',
            str_contains($h, 'dias comput') => 'dias',
            str_starts_with($h, 'gratificacion') => 'gratificacion',
            str_contains($h, '1/6') => 'sexto',
            str_contains($h, 'extra') => 'horas_extra',
            str_contains($h, 'comision') => 'comisiones',
            str_contains($h, 'base comput') => 'base',
            str_contains($h, 'depositar') => 'depositar',
            str_contains($h, '100%'), str_contains($h, 'cts 100') => 'cts_100',
            default => null,
        };
    }

    private static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($texto))));
    }

    private static function documento(mixed $valor): string
    {
        if (is_float($valor) && floor($valor) === $valor) {
            $valor = (int) $valor;
        }

        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', ltrim(trim((string) $valor), "'")));
    }

    /** null si está vacío, false si no es un número. */
    private static function numero(mixed $valor): float|false|null
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $texto = str_replace([',', 'S/', ' '], '', trim((string) $valor));

        return is_numeric($texto) ? (float) $texto : false;
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
                    throw new RuntimeException('El archivo contiene macros; guárdalo como .xlsx sin macros.');
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
