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
        'sede', 'area', 'horario', 'nombres', 'apellido_paterno', 'apellido_materno', 'tipo_documento', 'numero_documento',
        'fecha_nacimiento', 'celular_colaborador', 'celular_referencia', 'email', 'direccion',
        'cargo', 'tipo_contrato', 'tipo_trabajador', 'regimen_laboral', 'modalidad_trabajo',
        'fecha_ingreso', 'fecha_fin_contrato', 'salario', 'moneda_salario', 'periodicidad_pago',
        'asignacion_familiar', 'sistema_previsional',
        'pais_residencia', 'ciudad_residencia', 'distrito_residencia',
        'contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra',
        'cts_cuenta', 'banco', 'numero_cuenta', 'tipo_cuenta', 'moneda_cuenta', 'cci',
        // Estos dos completan la paridad exacta con StoreColaboradorRequest /
        // NuevoColaboradorModal (Sección: "el Excel debe ser igual que el
        // formulario") — antes faltaban.
        'tolerancia_particular_minutos', 'dias_descanso_rotativo_por_semana',
        'es_trabajador_confianza',
        // A diferencia del formulario manual (donde la empresa es siempre la
        // activa en la sesión), un Excel puede traer colaboradores de varias
        // empresas del mismo grupo — sin esta columna no había forma de
        // saber a cuál pertenece cada fila.
        'empresa',
    ];

    /** Columnas Sí/No que se convierten a booleano (null si vienen vacías). */
    private const CAMPOS_BOOLEANOS = ['contabilizar_tardanzas', 'contabilizar_faltas', 'contabilizar_horas_extra', 'es_trabajador_confianza'];

    /** Columnas agregadas después de la primera versión de la plantilla. */
    private const ENCABEZADOS_OPCIONALES = ['contabilizar_faltas'];

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
            if (! isset($encabezados[$columna]) && ! in_array($columna, self::ENCABEZADOS_OPCIONALES, true)) {
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
                $indice = $encabezados[$columna] ?? null;
                $fila[$columna] = $this->normalizarVacio($indice !== null ? ($valores[$indice] ?? null) : null);
            }

            // Compatibilidad con archivos descargados antes de incorporar
            // el flag independiente de faltas: conserva el comportamiento
            // histórico (las faltas sí afectaban por defecto).
            $fila['contabilizar_faltas'] ??= 'Sí';

            if ($fila['nombres'] === null || $fila['numero_documento'] === null) {
                $this->filasInvalidas++;

                continue;
            }

            $fila['nombres'] = mb_strtoupper($fila['nombres'], 'UTF-8');
            $fila['apellido_paterno'] = $fila['apellido_paterno'] !== null ? mb_strtoupper($fila['apellido_paterno'], 'UTF-8') : null;
            $fila['apellido_materno'] = $fila['apellido_materno'] !== null ? mb_strtoupper($fila['apellido_materno'], 'UTF-8') : null;
            $fila['tipo_documento'] = $fila['tipo_documento'] !== null ? Str::lower($fila['tipo_documento']) : null;
            $fila['tipo_contrato'] = $fila['tipo_contrato'] !== null ? Str::lower(str_replace(' ', '_', $fila['tipo_contrato'])) : null;
            $fila['tipo_trabajador'] = $fila['tipo_trabajador'] !== null ? Str::lower($fila['tipo_trabajador']) : null;
            $fila['modalidad_trabajo'] = $fila['modalidad_trabajo'] !== null ? Str::lower($fila['modalidad_trabajo']) : null;
            $fila['moneda_salario'] = $fila['moneda_salario'] !== null ? Str::upper($fila['moneda_salario']) : null;
            $fila['periodicidad_pago'] = $fila['periodicidad_pago'] !== null ? Str::lower($fila['periodicidad_pago']) : null;
            $fila['tipo_cuenta'] = $fila['tipo_cuenta'] !== null ? Str::lower($fila['tipo_cuenta']) : null;
            $fila['moneda_cuenta'] = $fila['moneda_cuenta'] !== null ? Str::upper($fila['moneda_cuenta']) : null;

            foreach (self::CAMPOS_BOOLEANOS as $campoBooleano) {
                $fila[$campoBooleano] = $fila[$campoBooleano] === null ? null : $this->esAfirmativo($fila[$campoBooleano]);
            }

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

    private function esAfirmativo(string $valor): bool
    {
        return in_array(Str::lower($valor), ['si', 'sí', 'true', '1', 'x'], true);
    }
}
