<?php

namespace App\Modules\Nominas\Domain\Cts;

/**
 * Archivo de planilla de CTS para BBVA (formato de la macro "CTS ONLINE" v3.1
 * de BBVA, hoja Cabecera/Detalle). Ancho fijo, una cabecera de 151
 * posiciones y un detalle de 272 por trabajador, separados por LF y sin salto
 * final, en ANSI (Windows-1252). Importes en céntimos sin punto.
 *
 * Cabecera: 1-3 "600" | 4-23 cuenta de cargo | 24-26 moneda | 27-41 importe
 * total | 42 tipo de proceso | 43-50 fecha | 51 hora | 52-76 referencia |
 * 77-82 total de registros | 83 validación de pertenencia | 84-98 valor de
 * control | 99-101 indicador | 102-131 descripción | 132-151 filler.
 *
 * Detalle: 1-3 "002" | 4 tipo DOI | 5-16 número DOI | 17 tipo de abono |
 * 18-37 cuenta CTS | 38-77 beneficiario | 78-92 importe | 93-132 referencia |
 * 133 indicador de aviso | 134-183 medio de aviso | 184-236 filler |
 * 237-251 últimas 4 remuneraciones brutas | 252-254 moneda | 255-272 filler.
 */
final class BbvaCtsTxtBuilder
{
    public const LONGITUD_CABECERA = 151;

    public const LONGITUD_DETALLE = 272;

    /** Tipo de documento de Agento => código BBVA (L: DNI, E: carné de extranjería, P: pasaporte). */
    public const TIPOS_DOI = ['dni' => 'L', 'ce' => 'E', 'carnet_extranjeria' => 'E', 'pasaporte' => 'P'];

    /**
     * @param  array<int, array{tipo_doi: string, numero_doi: string, cuenta: string, beneficiario: string, centimos: int}>  $detalles
     */
    public static function construir(string $cuentaCargo, string $referencia, array $detalles, string $moneda = 'PEN'): string
    {
        $total = array_sum(array_column($detalles, 'centimos'));
        $cabecera = '600'
            .self::cuenta($cuentaCargo)
            .$moneda
            .self::numero($total, 15)
            .'A'                          // proceso inmediato
            .str_repeat(' ', 8)           // fecha (solo proceso a fecha futura)
            .' '                          // hora (solo proceso por horario)
            .self::texto($referencia, 25)
            .self::numero(count($detalles), 6)
            .'N'                          // no valida pertenencia
            .str_repeat('0', 15)
            .'000'
            .str_repeat(' ', 30)
            .str_repeat(' ', 20);

        $lineas = [$cabecera];
        foreach ($detalles as $d) {
            $lineas[] = '002'
                .$d['tipo_doi']
                .self::texto($d['numero_doi'], 12)
                .'P'                      // abono en cuenta propia BBVA
                .self::texto(self::cuenta($d['cuenta']), 20)
                .self::texto($d['beneficiario'], 40)
                .self::numero($d['centimos'], 15)
                .str_repeat(' ', 40)      // referencia
                .' '                      // indicador de aviso
                .str_repeat(' ', 50)      // medio de aviso
                .str_repeat(' ', 53)
                .str_repeat('0', 15)      // últimas 4 remuneraciones brutas (opcional)
                .str_repeat(' ', 3)       // su moneda
                .str_repeat(' ', 18);
        }

        return mb_convert_encoding(implode("\n", $lineas), 'Windows-1252', 'UTF-8');
    }

    /** Cuenta BBVA de 20 dígitos; con 18 se completan los dígitos de control con "00" (igual que la macro). */
    public static function cuenta(string $cuenta): string
    {
        $limpia = preg_replace('/\D/', '', $cuenta) ?? '';

        return strlen($limpia) === 18 ? substr($limpia, 0, 8).'00'.substr($limpia, 8) : $limpia;
    }

    /** Texto en mayúsculas con los caracteres que acepta la macro, alineado a la izquierda. */
    public static function limpiarTexto(string $texto): string
    {
        $texto = mb_strtoupper(trim($texto), 'UTF-8');
        $texto = strtr($texto, ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U']);
        $texto = preg_replace('/[^A-Z0-9ÁÉÍÓÚÑ .\-*&\/$]/u', ' ', $texto) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    }

    private static function texto(string $texto, int $ancho): string
    {
        $texto = mb_substr(self::limpiarTexto($texto), 0, $ancho, 'UTF-8');

        return $texto.str_repeat(' ', $ancho - mb_strlen($texto, 'UTF-8'));
    }

    private static function numero(int $valor, int $ancho): string
    {
        return str_pad((string) $valor, $ancho, '0', STR_PAD_LEFT);
    }
}
