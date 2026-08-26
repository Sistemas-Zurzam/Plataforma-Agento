<?php

namespace App\Modules\Personas\Infrastructure;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Lee la plantilla de colaboradores: una fila = un colaborador. Usa
 * PhpSpreadsheet con getFormattedValue() (toArray formatData=true) por la
 * misma razón que HorarioXlsxReader: evita que un documento que empieza en 0
 * pierda el cero por quedar en una celda numérica (Sección de Asistencia,
 * bug ya corregido en TransactionXlsxReader).
 */
class ColaboradorXlsxReader
{
    private const ENCABEZADOS = [
        'sede', 'area', 'horario', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento',
        'fecha_nacimiento', 'celular_colaborador', 'celular_referencia', 'email', 'direccion',
        'cargo', 'tipo_contrato', 'tipo_trabajador', 'regimen_laboral', 'modalidad_trabajo',
        'fecha_ingreso', 'fecha_fin_contrato', 'salario', 'moneda_salario', 'periodicidad_pago',
        'asignacion_familiar', 'sistema_previsional',
    ];

    private int $filasInvalidas = 0;

    public function filasInvalidas(): int
    {
        return $this->filasInvalidas;
    }

    /** @return array<int, array<string, mixed>> */
    public function leer(string $ruta): array
    {
        $this->filasInvalidas = 0;
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        // formatData=true a propósito, ver docblock de la clase.
        $filas = $hoja->toArray(null, true, true, false);

        if ($filas === []) {
            throw new RuntimeException('El archivo no contiene filas.');
        }

        $encabezados = array_flip(array_map(
            fn ($valor) => Str::slug((string) $valor, '_'),
            array_shift($filas),
        ));

        foreach (self::ENCABEZADOS as $columna) {
            if (! isset($encabezados[$columna])) {
                throw new RuntimeException("No se encontró la columna esperada \"{$columna}\" en la plantilla.");
            }
        }

        $resultado = [];
        foreach ($filas as $fila) {
            $valores = array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, $fila);
            if (($valores[$encabezados['numero_documento']] ?? '') === '' && ($valores[$encabezados['nombres']] ?? '') === '') {
                continue; // fila en blanco al final del archivo
            }

            $fila = [];
            foreach (self::ENCABEZADOS as $columna) {
                $fila[$columna] = $this->normalizarVacio($valores[$encabezados[$columna]] ?? null);
            }

            if ($fila['nombres'] === null || $fila['numero_documento'] === null) {
                $this->filasInvalidas++;

                continue;
            }

            $fila['nombres'] = mb_strtoupper($fila['nombres'], 'UTF-8');
            $fila['apellidos'] = $fila['apellidos'] !== null ? mb_strtoupper($fila['apellidos'], 'UTF-8') : null;
            $fila['tipo_documento'] = $fila['tipo_documento'] !== null ? Str::lower($fila['tipo_documento']) : null;
            $fila['tipo_contrato'] = $fila['tipo_contrato'] !== null ? Str::lower(str_replace(' ', '_', $fila['tipo_contrato'])) : null;
            $fila['tipo_trabajador'] = $fila['tipo_trabajador'] !== null ? Str::lower($fila['tipo_trabajador']) : null;
            $fila['modalidad_trabajo'] = $fila['modalidad_trabajo'] !== null ? Str::lower($fila['modalidad_trabajo']) : null;
            $fila['moneda_salario'] = $fila['moneda_salario'] !== null ? Str::upper($fila['moneda_salario']) : null;
            $fila['periodicidad_pago'] = $fila['periodicidad_pago'] !== null ? Str::lower($fila['periodicidad_pago']) : null;

            $resultado[] = $fila;
        }

        if ($resultado === []) {
            throw new RuntimeException('El archivo no contiene filas de colaborador válidas.');
        }

        return $resultado;
    }

    private function normalizarVacio(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }
}
