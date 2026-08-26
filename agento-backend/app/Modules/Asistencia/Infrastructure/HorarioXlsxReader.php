<?php

namespace App\Modules\Asistencia\Infrastructure;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Lee la plantilla de horarios (una fila por horario+día, 7 filas por
 * horario). Usa PhpSpreadsheet en vez del parseo manual de TransactionXlsxReader
 * a propósito: getFormattedValue() respeta el formato de celda, así que una
 * columna de hora/día forzada a texto en la plantilla nunca sufre el problema
 * de "número sin cero a la izquierda" que tuvimos con el importador de
 * marcaciones.
 */
class HorarioXlsxReader
{
    private const ENCABEZADOS = [
        'nombre_horario', 'tipo_turno', 'tolerancia_minutos', 'cruza_medianoche',
        'descripcion', 'vigencia_desde', 'vigencia_hasta', 'dia', 'estado_dia',
        'hora_entrada', 'hora_salida', 'refrigerio_inicio', 'refrigerio_fin',
        'permitir_horas_extra', 'jornada_nocturna',
    ];

    private const DIAS_SEMANA = [
        'lunes' => 0, 'martes' => 1, 'miercoles' => 2, 'miércoles' => 2,
        'jueves' => 3, 'viernes' => 4, 'sabado' => 5, 'sábado' => 5, 'domingo' => 6,
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
        // formatData=true (getFormattedValue) es a propósito: respeta el
        // formato de celda TEXTO de la plantilla y evita el mismo bug de
        // "número sin cero a la izquierda" que tuvo TransactionXlsxReader.
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
            $nombreHorario = (string) ($valores[$encabezados['nombre_horario']] ?? '');
            $diaTexto = (string) ($valores[$encabezados['dia']] ?? '');

            if ($nombreHorario === '' && $diaTexto === '') {
                continue; // fila en blanco al final del archivo
            }

            $diaSemana = self::DIAS_SEMANA[Str::lower($diaTexto)] ?? null;
            if ($nombreHorario === '' || $diaSemana === null) {
                $this->filasInvalidas++;

                continue;
            }

            $resultado[] = [
                'nombre_horario' => $nombreHorario,
                'tipo_turno' => $this->normalizarVacio($valores[$encabezados['tipo_turno']] ?? null),
                'tolerancia_minutos' => $this->normalizarVacio($valores[$encabezados['tolerancia_minutos']] ?? null),
                'cruza_medianoche' => $this->esAfirmativo($valores[$encabezados['cruza_medianoche']] ?? null),
                'descripcion' => $this->normalizarVacio($valores[$encabezados['descripcion']] ?? null),
                'vigencia_desde' => $this->normalizarVacio($valores[$encabezados['vigencia_desde']] ?? null),
                'vigencia_hasta' => $this->normalizarVacio($valores[$encabezados['vigencia_hasta']] ?? null),
                'dia_semana' => $diaSemana,
                'estado' => Str::lower((string) ($valores[$encabezados['estado_dia']] ?? '')),
                'hora_entrada' => $this->normalizarHora($valores[$encabezados['hora_entrada']] ?? null),
                'hora_salida' => $this->normalizarHora($valores[$encabezados['hora_salida']] ?? null),
                'refrigerio_inicio' => $this->normalizarHora($valores[$encabezados['refrigerio_inicio']] ?? null),
                'refrigerio_fin' => $this->normalizarHora($valores[$encabezados['refrigerio_fin']] ?? null),
                'permitir_horas_extra' => $this->esAfirmativo($valores[$encabezados['permitir_horas_extra']] ?? null),
                'jornada_nocturna' => $this->esAfirmativo($valores[$encabezados['jornada_nocturna']] ?? null),
            ];
        }

        if ($resultado === []) {
            throw new RuntimeException('El archivo no contiene filas de horario válidas.');
        }

        return $resultado;
    }

    private function normalizarVacio(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        return $texto === '' ? null : $texto;
    }

    /**
     * Acepta "8:30" o "08:30" y siempre devuelve "HH:mm" — la validación de
     * HorarioService exige el formato con cero a la izquierda.
     */
    private function normalizarHora(mixed $valor): ?string
    {
        $texto = $this->normalizarVacio($valor);
        if ($texto === null || ! preg_match('/^(\d{1,2}):(\d{2})$/', $texto, $partes)) {
            return $texto;
        }

        return sprintf('%02d:%02d', (int) $partes[1], (int) $partes[2]);
    }

    private function esAfirmativo(mixed $valor): bool
    {
        return in_array(Str::lower(trim((string) ($valor ?? ''))), ['si', 'sí', 'true', '1', 'x'], true);
    }
}
