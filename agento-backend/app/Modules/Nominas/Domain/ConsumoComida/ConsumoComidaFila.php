<?php

namespace App\Modules\Nominas\Domain\ConsumoComida;

use DateTimeImmutable;

/**
 * Reglas de formato de una fila de la plantilla oficial de consumos de
 * comida, sin base de datos ni HTTP. El dinero se maneja en centavos enteros:
 * nunca se convierte el monto a float.
 *
 * Fechas aceptadas: número de serie de Excel (celda con formato fecha) o texto
 * AAAA-MM-DD. Cualquier otro texto (p. ej. 01/02/2026) se rechaza porque es
 * ambiguo entre día/mes y mes/día.
 */
final class ConsumoComidaFila
{
    public const COLUMNAS = ['documento', 'fecha_consumo', 'concepto', 'monto', 'referencia'];

    public const MONTO_MAXIMO_CENTAVOS = 9_999_999;

    // 1900-01-01 .. 2099-12-31 en número de serie de Excel (sistema 1900).
    private const SERIE_EXCEL_MINIMA = 1;

    private const SERIE_EXCEL_MAXIMA = 73050;

    /**
     * @param  array<string, mixed>  $valores  valor crudo de cada columna
     * @param  array<string, bool>  $formulas  columnas cuya celda es una fórmula
     * @return array{documento: ?string, fecha_consumo: ?string, concepto: ?string, monto_centavos: ?int, referencia: ?string, errores: list<string>}
     */
    public static function normalizar(array $valores, array $formulas = []): array
    {
        $normalizadores = [
            'documento' => self::documento(...),
            'fecha_consumo' => self::fecha(...),
            'concepto' => self::concepto(...),
            'monto' => self::montoCentavos(...),
            'referencia' => self::referencia(...),
        ];

        $resultado = [];
        $errores = [];
        foreach ($normalizadores as $columna => $normalizar) {
            // Nunca se evalúa una fórmula: el valor importable debe estar
            // escrito en la celda.
            if ($formulas[$columna] ?? false) {
                $resultado[$columna] = null;
                $errores[] = "La columna {$columna} contiene una fórmula; ingresa el valor directamente.";

                continue;
            }
            [$resultado[$columna], $error] = $normalizar($valores[$columna] ?? null);
            if ($error !== null) {
                $errores[] = $error;
            }
        }

        return [
            'documento' => $resultado['documento'],
            'fecha_consumo' => $resultado['fecha_consumo'],
            'concepto' => $resultado['concepto'],
            'monto_centavos' => $resultado['monto'],
            'referencia' => $resultado['referencia'],
            'errores' => $errores,
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function documento(mixed $valor): array
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return [null, 'El documento es obligatorio.'];
        }
        // Un número pierde los ceros iniciales (00123456 → 123456): la
        // plantilla define la columna como texto y no se adivina el original.
        if (! is_string($valor)) {
            return [null, 'El documento debe ingresarse como texto para conservar los ceros iniciales.'];
        }
        $documento = trim($valor);
        if (! preg_match('/^[A-Za-z0-9-]{1,20}$/', $documento)) {
            return [null, 'El documento solo admite letras, números y guiones (máximo 20 caracteres).'];
        }

        return [$documento, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function fecha(mixed $valor): array
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return [null, 'La fecha de consumo es obligatoria.'];
        }
        if (is_int($valor) || is_float($valor)) {
            if ($valor < self::SERIE_EXCEL_MINIMA || $valor > self::SERIE_EXCEL_MAXIMA || floor($valor) != $valor) {
                return [null, 'La fecha de consumo no es una fecha de Excel válida.'];
            }
            // Serie 1900 de Excel: el día 60 es el 29/02/1900 inexistente.
            $dias = (int) $valor;
            $base = new DateTimeImmutable($dias >= 60 ? '1899-12-30' : '1899-12-31');

            return [$base->modify("+{$dias} days")->format('Y-m-d'), null];
        }
        $texto = trim((string) $valor);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $partes) || ! checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return [null, 'La fecha de consumo debe ser una fecha de Excel o texto con formato AAAA-MM-DD.'];
        }

        return [$texto, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function concepto(mixed $valor): array
    {
        $texto = is_string($valor) || is_int($valor) || is_float($valor) ? trim((string) $valor) : '';
        if ($texto === '') {
            return [null, 'El concepto es obligatorio.'];
        }
        if (mb_strlen($texto) > 150) {
            return [null, 'El concepto no puede superar 150 caracteres.'];
        }

        return [$texto, null];
    }

    /** @return array{0: ?int, 1: ?string} */
    public static function montoCentavos(mixed $valor): array
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return [null, 'El monto es obligatorio.'];
        }
        if (is_int($valor)) {
            $texto = (string) $valor;
        } elseif (is_float($valor)) {
            // Representación decimal corta del número guardado en la celda
            // (12.35 → "12.35"); los decimales extra se rechazan abajo.
            $texto = rtrim(rtrim(sprintf('%.6F', $valor), '0'), '.');
        } elseif (is_string($valor)) {
            $texto = trim($valor);
        } else {
            return [null, 'El monto debe ser un número.'];
        }

        if (preg_match('/^-/', $texto)) {
            return [null, 'El monto debe ser positivo.'];
        }
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $texto, $partes)) {
            return [null, 'El monto debe ser un número con punto decimal (ej. 12.50).'];
        }
        $decimales = $partes[2] ?? '';
        if (strlen($decimales) > 2) {
            return [null, 'El monto admite como máximo dos decimales.'];
        }
        $entero = ltrim($partes[1], '0') ?: '0';
        if (strlen($entero) > 7) {
            return [null, 'El monto excede el máximo permitido.'];
        }
        $centavos = (int) $entero * 100 + (int) str_pad($decimales, 2, '0');
        if ($centavos <= 0) {
            return [null, 'El monto debe ser mayor que cero.'];
        }
        if ($centavos > self::MONTO_MAXIMO_CENTAVOS) {
            return [null, 'El monto excede el máximo permitido.'];
        }

        return [$centavos, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    public static function referencia(mixed $valor): array
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return [null, null];
        }
        if (is_float($valor) && floor($valor) != $valor) {
            return [null, 'La referencia debe ingresarse como texto.'];
        }
        $texto = is_float($valor) ? sprintf('%.0F', $valor) : trim((string) $valor);
        if (mb_strlen($texto) > 100) {
            return [null, 'La referencia no puede superar 100 caracteres.'];
        }

        return [$texto, null];
    }

    /** Clave de comparación para posibles duplicados sin referencia. */
    public static function claveDuplicado(string $documento, string $fecha, string $concepto, int $centavos): string
    {
        return implode('|', [$documento, $fecha, mb_strtolower(preg_replace('/\s+/', ' ', $concepto)), $centavos]);
    }

    public static function centavosATexto(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    public static function textoACentavos(string $monto): int
    {
        [$entero, $decimales] = array_pad(explode('.', $monto, 2), 2, '0');

        return (int) $entero * 100 + (int) str_pad(substr($decimales, 0, 2), 2, '0');
    }
}
