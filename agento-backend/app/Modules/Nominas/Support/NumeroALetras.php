<?php

namespace App\Modules\Nominas\Support;

/**
 * Monto en letras "ENTERO Y CENTAVOS/100 MONEDA" de las boletas de pago.
 * Misma salida que agento-frontend/src/modules/remuneraciones/utils/numeroALetras.js,
 * para que la boleta impresa y el PDF por correo digan lo mismo.
 */
final class NumeroALetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];

    private const DIEZ_A_DIECINUEVE = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];

    private const DECENAS = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    private const MONEDAS = ['PEN' => 'SOLES', 'USD' => 'DOLARES'];

    public static function convertir(float $monto, ?string $moneda = 'PEN'): string
    {
        $valor = abs($monto);
        $entero = (int) floor($valor);
        $centavos = (int) round(($valor - $entero) * 100);
        $letras = $entero === 0 ? 'CERO' : self::millones($entero);

        return sprintf('%s Y %02d/100 %s', $letras, $centavos, self::MONEDAS[$moneda ?? 'PEN'] ?? self::MONEDAS['PEN']);
    }

    private static function decenas(int $n): string
    {
        if ($n < 10) {
            return self::UNIDADES[$n];
        }
        if ($n < 20) {
            return self::DIEZ_A_DIECINUEVE[$n - 10];
        }
        $decena = intdiv($n, 10);
        $unidad = $n % 10;
        if ($decena === 2) {
            return $unidad === 0 ? 'VEINTE' : 'VEINTI'.self::UNIDADES[$unidad];
        }

        return $unidad === 0 ? self::DECENAS[$decena] : self::DECENAS[$decena].' Y '.self::UNIDADES[$unidad];
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $centena = intdiv($n, 100);
        $resto = $n % 100;

        return implode(' ', array_filter([$centena > 0 ? self::CENTENAS[$centena] : '', $resto > 0 ? self::decenas($resto) : '']));
    }

    /** "UNO"/"VEINTIUNO" se apocopa a "UN"/"VEINTIUN" delante de MIL/MILLONES. */
    private static function apocopar(string $letras): string
    {
        return str_ends_with($letras, 'UNO') ? substr($letras, 0, -3).'UN' : $letras;
    }

    private static function miles(int $n): string
    {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $letras = $miles > 0 ? ($miles === 1 ? 'MIL' : self::apocopar(self::centenas($miles)).' MIL') : '';

        return implode(' ', array_filter([$letras, $resto > 0 ? self::centenas($resto) : '']));
    }

    private static function millones(int $n): string
    {
        $millones = intdiv($n, 1000000);
        $resto = $n % 1000000;
        $letras = $millones > 0 ? ($millones === 1 ? 'UN MILLON' : self::apocopar(self::miles($millones)).' MILLONES') : '';

        return implode(' ', array_filter([$letras, $resto > 0 ? self::miles($resto) : '']));
    }
}
