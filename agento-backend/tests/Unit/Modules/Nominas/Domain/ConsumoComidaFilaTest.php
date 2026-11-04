<?php

namespace Tests\Unit\Modules\Nominas\Domain;

use App\Modules\Nominas\Domain\ConsumoComida\ConsumoComidaFila;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConsumoComidaFilaTest extends TestCase
{
    public function test_documento_conserva_ceros_iniciales_y_rechaza_numeros(): void
    {
        $this->assertSame(['00123456', null], ConsumoComidaFila::documento(' 00123456 '));
        [$documento, $error] = ConsumoComidaFila::documento(123456);
        $this->assertNull($documento);
        $this->assertStringContainsString('texto', $error);
    }

    public function test_fecha_acepta_serie_excel_y_texto_iso(): void
    {
        $this->assertSame(['2026-10-05', null], ConsumoComidaFila::fecha('2026-10-05'));
        // 46300 = 2026-10-05 en el sistema de fechas 1900 de Excel.
        $this->assertSame(['2026-10-05', null], ConsumoComidaFila::fecha(46300));
    }

    #[DataProvider('fechasInvalidas')]
    public function test_fecha_rechaza_formatos_ambiguos_o_invalidos(mixed $valor): void
    {
        [$fecha, $error] = ConsumoComidaFila::fecha($valor);
        $this->assertNull($fecha);
        $this->assertNotNull($error);
    }

    public static function fechasInvalidas(): array
    {
        return [['05/10/2026'], ['2026-02-30'], ['ayer'], [46300.5], [null], ['']];
    }

    public function test_monto_se_convierte_a_centavos_sin_float(): void
    {
        $this->assertSame([1250, null], ConsumoComidaFila::montoCentavos('12.50'));
        $this->assertSame([1235, null], ConsumoComidaFila::montoCentavos(12.35));
        $this->assertSame([700, null], ConsumoComidaFila::montoCentavos(7));
        $this->assertSame('12.50', ConsumoComidaFila::centavosATexto(1250));
        $this->assertSame(1250, ConsumoComidaFila::textoACentavos('12.50'));
    }

    #[DataProvider('montosInvalidos')]
    public function test_monto_rechaza_negativos_cero_decimales_extra_y_texto(mixed $valor): void
    {
        [$centavos, $error] = ConsumoComidaFila::montoCentavos($valor);
        $this->assertNull($centavos);
        $this->assertNotNull($error);
    }

    public static function montosInvalidos(): array
    {
        return [['-5'], [-5], ['0'], [0], ['12.345'], [12.345], ['12,50'], ['doce'], [null]];
    }

    public function test_formulas_se_reportan_sin_evaluarse(): void
    {
        $resultado = ConsumoComidaFila::normalizar(
            ['documento' => '00123456', 'fecha_consumo' => '2026-10-05', 'concepto' => 'Almuerzo', 'monto' => null, 'referencia' => null],
            ['monto' => true],
        );

        $this->assertNull($resultado['monto_centavos']);
        $this->assertCount(1, $resultado['errores']);
        $this->assertStringContainsString('fórmula', $resultado['errores'][0]);
    }
}
