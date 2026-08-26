<?php

namespace App\Modules\Asistencia\Infrastructure;

use Illuminate\Support\Carbon;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class TransactionXlsxReader
{
    private int $filasInvalidas = 0;

    public function filasInvalidas(): int
    {
        return $this->filasInvalidas;
    }

    /** @return array<int, array<string, mixed>> */
    public function leer(string $ruta): array
    {
        $this->filasInvalidas = 0;
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $compartidas = $this->cadenasCompartidas($zip);
            $contenido = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($contenido === false) {
                throw new RuntimeException('El archivo no contiene la hoja de marcaciones esperada.');
            }
            $xml = simplexml_load_string($contenido);
            if (! $xml instanceof SimpleXMLElement) {
                throw new RuntimeException('La hoja de marcaciones no es válida.');
            }
            $xml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $filas = $xml->xpath('//x:sheetData/x:row') ?: [];
            $encabezados = null;
            $resultado = [];

            foreach ($filas as $fila) {
                $valores = $this->valoresFila($fila, $compartidas);
                if ($encabezados === null) {
                    if (in_array('Person ID', $valores, true) && in_array('Punch Time', $valores, true)) {
                        $encabezados = array_flip($valores);
                    }
                    continue;
                }

                $personId = trim((string) ($valores[$encabezados['Person ID']] ?? ''));
                // Si la columna "Person ID" quedó como celda numérica en el
                // Excel, el XML no puede conservar el cero inicial de un DNI
                // (09642096 se guarda como 9642096) — se rellena a 8 dígitos
                // para que el match contra numero_documento no falle en silencio.
                if ($personId !== '' && ctype_digit($personId) && strlen($personId) < 8) {
                    $personId = str_pad($personId, 8, '0', STR_PAD_LEFT);
                }
                $serial = $valores[$encabezados['Punch Time']] ?? null;
                if ($personId === '' || ! is_numeric($serial)) {
                    $this->filasInvalidas++;
                    continue;
                }

                $resultado[] = [
                    'person_id' => $personId,
                    'nombre' => $valores[$encabezados['Person Name']] ?? null,
                    'marcado_at' => $this->fechaExcel((float) $serial),
                    'origen' => $valores[$encabezados['Source']] ?? 'Attendance Device',
                    'dispositivo' => $valores[$encabezados['Device Name']] ?? null,
                    'numero_serie' => $valores[$encabezados['Device SN']] ?? null,
                    'modo_verificacion' => $valores[$encabezados['Verification Mode']] ?? null,
                ];
            }

            if ($encabezados === null) {
                throw new RuntimeException('No se encontraron los encabezados Person ID y Punch Time.');
            }

            return $resultado;
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function cadenasCompartidas(ZipArchive $zip): array
    {
        $contenido = $zip->getFromName('xl/sharedStrings.xml');
        if ($contenido === false) return [];
        $xml = simplexml_load_string($contenido);
        $xml?->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        return array_map(function (SimpleXMLElement $item) {
            $item->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $textos = $item->xpath('.//x:t') ?: [];
            return implode('', array_map(fn ($texto) => (string) $texto, $textos));
        }, $xml?->xpath('//x:si') ?: []);
    }

    /** @return array<int, string> */
    private function valoresFila(SimpleXMLElement $fila, array $compartidas): array
    {
        $fila->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $valores = [];
        foreach ($fila->xpath('./x:c') ?: [] as $celda) {
            $referencia = (string) $celda['r'];
            preg_match('/^[A-Z]+/', $referencia, $coincidencia);
            $indice = $this->indiceColumna($coincidencia[0]);
            $valor = (string) ($celda->v ?? '');
            $valores[$indice] = ((string) $celda['t'] === 's' && $valor !== '') ? ($compartidas[(int) $valor] ?? '') : $valor;
        }
        if ($valores === []) return [];
        return array_replace(array_fill(0, max(array_keys($valores)) + 1, ''), $valores);
    }

    private function indiceColumna(string $letras): int
    {
        $numero = 0;
        foreach (str_split($letras) as $letra) $numero = ($numero * 26) + (ord($letra) - 64);
        return $numero - 1;
    }

    private function fechaExcel(float $serial): Carbon
    {
        $dias = (int) floor($serial);
        $segundos = (int) round(($serial - $dias) * 86400);
        return Carbon::create(1899, 12, 30)->addDays($dias)->addSeconds($segundos);
    }
}
