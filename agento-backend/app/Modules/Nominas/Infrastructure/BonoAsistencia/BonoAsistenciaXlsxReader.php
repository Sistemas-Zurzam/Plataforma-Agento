<?php

namespace App\Modules\Nominas\Infrastructure\BonoAsistencia;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Lee el Excel que Livex regresa (ver BonoAsistenciaExcelExporter para el
 * archivo que se les envía originalmente) — mismo patrón que
 * AntecedenteHistoricoXlsxReader (PhpSpreadsheet directo, encabezados vía
 * Str::slug, formatData=true para no perder el DNI como texto). Puramente
 * parseo: sin persistencia ni consultas a base de datos — el match por DNI
 * y la interpretación de las columnas vive en BonoAsistenciaService.
 */
class BonoAsistenciaXlsxReader
{
    private const COLUMNA_DOCUMENTO = 'documento';

    // Str::slug() colapsa "Si/No" a "sino" (la barra se elimina sin dejar
    // separador, no se convierte en "_"): estas constantes deben coincidir
    // con lo que el slug REALMENTE produce para el encabezado de
    // BonoAsistenciaExcelExporter, no con lo que "debería" leerse a simple
    // vista — verificado con Str::slug('Cumplio meta comercial (Si/No)', '_').
    private const COLUMNA_META_COMERCIAL = 'cumplio_meta_comercial_sino';

    private const COLUMNA_APROBADO = 'aprobado_sino';

    private const COLUMNA_CORRECCION_INJUSTIFICADA = 'corregir_dias_falta_injustificada_opcional';

    private const COLUMNA_OBSERVACION = 'observacion';

    private int $filasInvalidas = 0;

    public function filasInvalidas(): int
    {
        return $this->filasInvalidas;
    }

    /**
     * @return array<int, array{documento: string, meta_comercial_cumplida: mixed, aprobado: mixed, correccion_dias_falta_injustificada: mixed, observacion: mixed}>
     */
    public function leer(string $ruta): array
    {
        $this->filasInvalidas = 0;

        // formatData=true a propósito (mismo motivo que AntecedenteHistoricoXlsxReader):
        // evita que un documento que empieza en 0 pierda el cero por quedar
        // en una celda numérica.
        $filas = IOFactory::load($ruta)->getActiveSheet()->toArray(null, true, true, false);

        if ($filas === []) {
            throw new RuntimeException('El archivo no contiene filas.');
        }

        $encabezados = array_flip(array_map(
            fn ($valor) => Str::slug((string) $valor, '_'),
            array_shift($filas),
        ));

        if (! isset($encabezados[self::COLUMNA_DOCUMENTO])) {
            throw new RuntimeException('No se encontró la columna "Documento" en el archivo.');
        }

        $resultado = [];
        foreach ($filas as $fila) {
            $documento = trim((string) ($fila[$encabezados[self::COLUMNA_DOCUMENTO]] ?? ''));
            if ($documento === '') {
                $this->filasInvalidas++;

                continue; // fila en blanco al final de la hoja
            }

            $resultado[] = [
                'documento' => $documento,
                'meta_comercial_cumplida' => $this->valor($fila, $encabezados, self::COLUMNA_META_COMERCIAL),
                'aprobado' => $this->valor($fila, $encabezados, self::COLUMNA_APROBADO),
                'correccion_dias_falta_injustificada' => $this->valor($fila, $encabezados, self::COLUMNA_CORRECCION_INJUSTIFICADA),
                'observacion' => $this->valor($fila, $encabezados, self::COLUMNA_OBSERVACION),
            ];
        }

        return $resultado;
    }

    /** @param array<int, mixed> $fila @param array<string, int> $encabezados */
    private function valor(array $fila, array $encabezados, string $columna): mixed
    {
        if (! isset($encabezados[$columna])) {
            return null;
        }

        $valor = $fila[$encabezados[$columna]] ?? null;
        if (is_string($valor)) {
            $valor = trim($valor);

            return $valor === '' ? null : $valor;
        }

        return $valor;
    }
}
