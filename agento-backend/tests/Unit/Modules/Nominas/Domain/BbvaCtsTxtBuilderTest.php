<?php

namespace Tests\Unit\Modules\Nominas\Domain;

use App\Modules\Nominas\Domain\Cts\BbvaCtsTxtBuilder;
use PHPUnit\Framework\TestCase;

/** Posiciones del formato de la macro "CTS ONLINE" v3.1 de BBVA. Datos ficticios. */
class BbvaCtsTxtBuilderTest extends TestCase
{
    /** Posición 1-based e inclusiva, como la especifica la macro. */
    private static function campo(string $linea, int $desde, int $hasta): string
    {
        return substr($linea, $desde - 1, $hasta - $desde + 1);
    }

    private function txt(): array
    {
        $contenido = BbvaCtsTxtBuilder::construir('00110000000000000001', 'CTS NOVIEMBRE 2026', [
            ['tipo_doi' => 'L', 'numero_doi' => '12345678', 'cuenta' => '00110000000000000002', 'beneficiario' => 'Pérez Núñez, José', 'centimos' => 33104],
            ['tipo_doi' => 'E', 'numero_doi' => '001234567', 'cuenta' => '001100000000000003', 'beneficiario' => 'Gómez Ana', 'centimos' => 12889],
        ]);

        return explode("\n", mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252'));
    }

    public function test_cabecera(): void
    {
        $c = $this->txt()[0];

        $this->assertSame(BbvaCtsTxtBuilder::LONGITUD_CABECERA, strlen(mb_convert_encoding($c, 'Windows-1252', 'UTF-8')));
        $this->assertSame('600', self::campo($c, 1, 3));
        $this->assertSame('00110000000000000001', self::campo($c, 4, 23));
        $this->assertSame('PEN', self::campo($c, 24, 26));
        $this->assertSame('000000000045993', self::campo($c, 27, 41)); // 331.04 + 128.89
        $this->assertSame('A', self::campo($c, 42, 42));
        $this->assertSame(str_repeat(' ', 9), self::campo($c, 43, 51));
        $this->assertSame(str_pad('CTS NOVIEMBRE 2026', 25), self::campo($c, 52, 76));
        $this->assertSame('000002', self::campo($c, 77, 82));
        $this->assertSame('N', self::campo($c, 83, 83));
        $this->assertSame(str_repeat('0', 18), self::campo($c, 84, 101));
    }

    public function test_detalle(): void
    {
        [, $d1, $d2] = $this->txt();
        $bytes = mb_convert_encoding($d1, 'Windows-1252', 'UTF-8');

        $this->assertSame(BbvaCtsTxtBuilder::LONGITUD_DETALLE, strlen($bytes));
        $this->assertSame('002', self::campo($bytes, 1, 3));
        $this->assertSame('L', self::campo($bytes, 4, 4));
        $this->assertSame(str_pad('12345678', 12), self::campo($bytes, 5, 16));
        $this->assertSame('P', self::campo($bytes, 17, 17));
        $this->assertSame('00110000000000000002', self::campo($bytes, 18, 37));
        // Mayúsculas, sin coma, conserva tildes y Ñ (en Windows-1252).
        $this->assertSame('PÉREZ NÚÑEZ JOSÉ'.str_repeat(' ', 24), mb_convert_encoding(self::campo($bytes, 38, 77), 'UTF-8', 'Windows-1252'));
        $this->assertSame('000000000033104', self::campo($bytes, 78, 92));
        $this->assertSame(str_repeat(' ', 144), self::campo($bytes, 93, 236));
        $this->assertSame(str_repeat('0', 15), self::campo($bytes, 237, 251));
        $this->assertSame(str_repeat(' ', 21), self::campo($bytes, 252, 272));

        // Cuenta de 18 dígitos: se completan los dígitos de control con 00 (como la macro).
        $this->assertSame('00110000000000000003', self::campo($d2, 18, 37));
        $this->assertSame('E', self::campo($d2, 4, 4));
    }

    public function test_lineas_separadas_por_lf_sin_salto_final(): void
    {
        $contenido = BbvaCtsTxtBuilder::construir('00110000000000000001', 'CTS', [
            ['tipo_doi' => 'L', 'numero_doi' => '1', 'cuenta' => '00110000000000000002', 'beneficiario' => 'A', 'centimos' => 1],
        ]);

        $this->assertStringNotContainsString("\r", $contenido);
        $this->assertSame(1, substr_count($contenido, "\n"));
        $this->assertStringEndsNotWith("\n", $contenido);
    }
}
