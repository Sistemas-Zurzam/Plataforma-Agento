<?php

namespace App\Modules\Nominas\Infrastructure;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Lee "Data Planilla" y "Data Provisiones" del Excel real (`Resumen
 * Planillas Periodo... - Contabilidad`, ver el plan aprobado en
 * DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md). Mismo patrón que
 * ColaboradorXlsxReader/HorarioXlsxReader (PhpSpreadsheet directo,
 * encabezados vía Str::slug, formatData=true para no perder ceros
 * iniciales de documentos) — puramente parseo, sin persistencia ni
 * consultas a base de datos. La decisión de qué empresa corresponde a cada
 * fila (pre-filtro de privacidad) vive en el servicio que consume estas
 * filas, no aquí: este lector solo expone `empresa_informada` tal cual
 * viene en el Excel.
 *
 * Las demás hojas del archivo (Resusmen Planilla, Resumen de CTS., Resumen
 * Liquidaciones, Provisiones, Numero de Trabajadores, GRACE, Hoja1) son
 * tablas dinámicas de Excel con encabezados multi-fila y celdas combinadas
 * — no aptas para lectura fila-por-fila, y no se leen aquí.
 */
class AntecedenteHistoricoXlsxReader
{
    private const HOJA_PLANILLA = 'Data Planilla';

    private const HOJA_PROVISIONES = 'Data Provisiones';

    /** Encabezados reales confirmados de "Data Planilla" (slug con Str::slug(_)). */
    private const ENCABEZADOS_PLANILLA = [
        'dni', 'apellidos_y_nombres', 'cod_concepto', 'monto', 'mes', 'cod_mes', 'ano',
        'empresa', 'planilla', 'dato', 'concepto', 'estado', 'fecha_ingreso', 'fecha_cese',
        'estado_neto', 'cod_deposito', 'tipo_de_calculo',
    ];

    /** Encabezados reales confirmados de "Data Provisiones" (subconjunto que necesitamos). */
    private const ENCABEZADOS_PROVISIONES = [
        'documento', 'apellidos_y_nombres', 'fechaingreso', 'fechacese',
        'tipo_proceso', 'mes', 'cod_mes', 'ano', 'prov_mes', 'emprea',
    ];

    private int $filasInvalidas = 0;

    public function filasInvalidas(): int
    {
        return $this->filasInvalidas;
    }

    /**
     * @param  array<int, string>  $hojas
     * @return array<int, array<string, mixed>>
     */
    public function leer(string $ruta, array $hojas = [self::HOJA_PLANILLA, self::HOJA_PROVISIONES]): array
    {
        $this->filasInvalidas = 0;
        $resultado = [];

        foreach ($hojas as $nombreHoja) {
            $filasHoja = $nombreHoja === self::HOJA_PROVISIONES
                ? $this->leerProvisiones($ruta, $nombreHoja)
                : $this->leerPlanilla($ruta, $nombreHoja);
            array_push($resultado, ...$filasHoja);
        }

        if ($resultado === []) {
            throw new RuntimeException('El archivo no contiene filas de antecedentes en las hojas esperadas.');
        }

        return $resultado;
    }

    /** @return array<int, array<string, mixed>> */
    private function leerPlanilla(string $ruta, string $nombreHoja): array
    {
        [$encabezados, $filas] = $this->cargarHoja($ruta, $nombreHoja, self::ENCABEZADOS_PLANILLA);

        $resultado = [];
        foreach ($filas as $numeroFila => $fila) {
            $valores = $this->mapearFila($fila, $encabezados);

            $documento = $this->normalizarDocumento($valores['dni'] ?? null);
            $importe = $this->normalizarImporte($valores['monto'] ?? null);
            if ($documento === null && $importe === null) {
                $this->filasInvalidas++;

                continue; // fila en blanco al final de la hoja
            }

            $resultado[] = [
                'hoja_nombre' => $nombreHoja,
                'fila_numero' => $numeroFila,
                'datos_originales' => $valores,
                'empresa_informada' => $this->normalizarTexto($valores['empresa'] ?? null),
                'regimen_informado' => $this->normalizarTexto($valores['planilla'] ?? null),
                'tipo_documento_normalizado' => null, // nunca se asume DNI (corrección #2) — lo resuelve el servicio
                'numero_documento_normalizado' => $documento,
                'colaborador_nombre_original' => $this->normalizarTexto($valores['apellidos_y_nombres'] ?? null),
                'fecha_ingreso_vinculo' => $this->normalizarFecha($valores['fecha_ingreso'] ?? null),
                'fecha_fin_vinculo' => $this->normalizarFecha($valores['fecha_cese'] ?? null),
                'tipo_calculo_original' => $this->normalizarTexto($valores['tipo_de_calculo'] ?? null),
                'codigo_concepto_original' => $this->normalizarTexto($valores['cod_concepto'] ?? null),
                'nombre_concepto_original' => $this->normalizarTexto($valores['concepto'] ?? null),
                'anio' => $this->normalizarEntero($valores['ano'] ?? null),
                'mes' => $this->normalizarEntero($valores['cod_mes'] ?? null),
                'fecha_periodo_inicio' => null,
                'fecha_periodo_fin' => null,
                'fecha_pago_deposito' => null, // el Excel no trae una fecha de pago verificable — ver plan aprobado
                'fecha_corte' => null, // se completa con la fecha de corte del lote
                'importe' => $importe,
                'dias_cantidad' => null,
                'estado_excel' => $this->normalizarTexto($valores['estado_neto'] ?? null),
            ];
        }

        return $resultado;
    }

    /**
     * "Data Provisiones" siempre clasifica no_aplicable (una provisión
     * nunca es un pago — ver ClasificadorAntecedenteHistorico y el plan
     * aprobado), así que solo se extrae lo necesario para el pre-filtro de
     * empresa y la trazabilidad, no el detalle completo de columnas de esa
     * hoja (81 columnas, en su mayoría desgloses día a día sin relevancia
     * para un antecedente).
     *
     * @return array<int, array<string, mixed>>
     */
    private function leerProvisiones(string $ruta, string $nombreHoja): array
    {
        [$encabezados, $filas] = $this->cargarHoja($ruta, $nombreHoja, self::ENCABEZADOS_PROVISIONES);

        $resultado = [];
        foreach ($filas as $numeroFila => $fila) {
            $valores = $this->mapearFila($fila, $encabezados);

            $documento = $this->normalizarDocumento($valores['documento'] ?? null);
            $importe = $this->normalizarImporte($valores['prov_mes'] ?? null);
            if ($documento === null && $importe === null) {
                $this->filasInvalidas++;

                continue;
            }

            $resultado[] = [
                'hoja_nombre' => $nombreHoja,
                'fila_numero' => $numeroFila,
                'datos_originales' => $valores,
                'empresa_informada' => $this->normalizarTexto($valores['emprea'] ?? null),
                'regimen_informado' => null,
                'tipo_documento_normalizado' => null,
                'numero_documento_normalizado' => $documento,
                'colaborador_nombre_original' => $this->normalizarTexto($valores['apellidos_y_nombres'] ?? null),
                'fecha_ingreso_vinculo' => $this->normalizarFecha($valores['fechaingreso'] ?? null),
                'fecha_fin_vinculo' => $this->normalizarFecha($valores['fechacese'] ?? null),
                // "TIPO PROCESO" (ej. "PROV. CTS") se guarda como tipo de cálculo original para
                // trazabilidad, aunque el clasificador nunca la evalúa: esta hoja es siempre no_aplicable.
                'tipo_calculo_original' => $this->normalizarTexto($valores['tipo_proceso'] ?? null),
                'codigo_concepto_original' => null,
                'nombre_concepto_original' => $this->normalizarTexto($valores['tipo_proceso'] ?? null),
                'anio' => $this->normalizarEntero($valores['ano'] ?? null),
                'mes' => $this->normalizarEntero($valores['cod_mes'] ?? null),
                'fecha_periodo_inicio' => null,
                'fecha_periodo_fin' => null,
                'fecha_pago_deposito' => null,
                'fecha_corte' => null,
                'importe' => $importe,
                'dias_cantidad' => null,
                'estado_excel' => null,
                // Marca de origen: el servicio la usa para saltarse el
                // clasificador y fijar no_aplicable directo, sin necesitar
                // TIPO DE CALCULO/CONCEPTO/ESTADO NETO (esta hoja no los tiene).
                'es_provision' => true,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<int, string>  $encabezadosEsperados
     * @return array{0: array<string, int>, 1: array<int, array<int, mixed>>} [mapa columna=>índice, filas de datos sin encabezado]
     */
    private function cargarHoja(string $ruta, string $nombreHoja, array $encabezadosEsperados): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$nombreHoja]);
        $spreadsheet = $reader->load($ruta);
        $hoja = $spreadsheet->getSheetByName($nombreHoja);

        if (! $hoja) {
            throw new RuntimeException("No se encontró la hoja \"{$nombreHoja}\" en el archivo.");
        }

        // formatData=true a propósito (mismo motivo que ColaboradorXlsxReader):
        // evita que un documento que empieza en 0 pierda el cero por quedar en
        // una celda numérica.
        $filas = $hoja->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        if ($filas === []) {
            throw new RuntimeException("La hoja \"{$nombreHoja}\" no contiene filas.");
        }

        $encabezados = array_flip(array_map(
            fn ($valor) => Str::slug((string) $valor, '_'),
            array_shift($filas),
        ));

        foreach ($encabezadosEsperados as $columna) {
            if (! isset($encabezados[$columna])) {
                throw new RuntimeException("No se encontró la columna esperada \"{$columna}\" en la hoja \"{$nombreHoja}\".");
            }
        }

        // Las filas de datos empiezan en la fila 2 del Excel (la 1 es el encabezado ya consumido).
        $filasNumeradas = [];
        $numeroFila = 2;
        foreach ($filas as $fila) {
            $filasNumeradas[$numeroFila] = $fila;
            $numeroFila++;
        }

        return [$encabezados, $filasNumeradas];
    }

    /** @param  array<int, mixed>  $fila  @param  array<string, int>  $encabezados  @return array<string, mixed> */
    private function mapearFila(array $fila, array $encabezados): array
    {
        $valores = [];
        foreach ($encabezados as $columna => $indice) {
            $valores[$columna] = $fila[$indice] ?? null;
        }

        return $valores;
    }

    private function normalizarTexto(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }

    /** Preserva ceros iniciales — nunca se interpreta como número (corrección #2: no asumir DNI ni su formato). */
    private function normalizarDocumento(mixed $valor): ?string
    {
        return $this->normalizarTexto($valor);
    }

    private function normalizarEntero(mixed $valor): ?int
    {
        $texto = $this->normalizarTexto($valor);

        return $texto !== null && is_numeric($texto) ? (int) $texto : null;
    }

    /** Quita separadores de miles antes de castear — el formato de celda puede traer comas (ej. "23,228.47"). */
    private function normalizarImporte(mixed $valor): ?float
    {
        $texto = $this->normalizarTexto($valor);
        if ($texto === null) {
            return null;
        }
        $limpio = str_replace(',', '', $texto);

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    /** Convierte un serial de fecha de Excel (ej. 45962) o una fecha de texto (ej. "16/04/2026") a Y-m-d. */
    private function normalizarFecha(mixed $valor): ?string
    {
        $texto = $this->normalizarTexto($valor);
        if ($texto === null) {
            return null;
        }

        if (is_numeric($texto)) {
            return ExcelDate::excelToDateTimeObject((float) $texto)->format('Y-m-d');
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $texto)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
