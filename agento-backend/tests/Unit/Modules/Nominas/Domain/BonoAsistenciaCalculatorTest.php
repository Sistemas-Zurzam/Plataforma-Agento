<?php

namespace Tests\Unit\Modules\Nominas\Domain;

use App\Modules\Nominas\Domain\BonoAsistenciaCalculator;
use PHPUnit\Framework\TestCase;

class BonoAsistenciaCalculatorTest extends TestCase
{
    private BonoAsistenciaCalculator $calculador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculador = new BonoAsistenciaCalculator;
    }

    public function test_sin_faltas_y_menos_de_3_tardanzas_da_el_100_por_ciento(): void
    {
        $resultado = $this->calculador->calcular(0, 0, 2, 150.0, null);

        $this->assertSame(100, $resultado['porcentaje']);
        $this->assertEquals(150.0, $resultado['monto']);
    }

    public function test_una_falta_justificada_sin_meta_comercial_da_50_por_ciento(): void
    {
        $resultado = $this->calculador->calcular(1, 0, 0, 150.0, null);

        $this->assertSame(50, $resultado['porcentaje']);
        $this->assertEquals(75.0, $resultado['monto']);
    }

    public function test_una_falta_justificada_con_meta_comercial_recupera_el_100_por_ciento(): void
    {
        $resultado = $this->calculador->calcular(1, 0, 0, 150.0, true);

        $this->assertSame(100, $resultado['porcentaje']);
        $this->assertEquals(150.0, $resultado['monto']);
    }

    public function test_dos_faltas_justificadas_sin_meta_comercial_pierde_el_100_por_ciento(): void
    {
        $resultado = $this->calculador->calcular(2, 0, 0, 150.0, false);

        $this->assertSame(0, $resultado['porcentaje']);
        $this->assertEquals(0.0, $resultado['monto']);
    }

    public function test_dos_faltas_justificadas_con_meta_comercial_recupera_50_por_ciento(): void
    {
        $resultado = $this->calculador->calcular(2, 0, 0, 150.0, true);

        $this->assertSame(50, $resultado['porcentaje']);
        $this->assertEquals(75.0, $resultado['monto']);
    }

    public function test_falta_injustificada_pierde_el_100_por_ciento_sin_recuperacion_aunque_cumpla_meta(): void
    {
        $resultado = $this->calculador->calcular(0, 1, 0, 150.0, true);

        $this->assertSame(0, $resultado['porcentaje']);
    }

    public function test_tres_tardanzas_pierde_el_100_por_ciento_sin_recuperacion(): void
    {
        $resultado = $this->calculador->calcular(0, 0, 3, 150.0, true);

        $this->assertSame(0, $resultado['porcentaje']);
    }

    public function test_falta_injustificada_prevalece_sobre_faltas_justificadas_y_tardanzas(): void
    {
        $resultado = $this->calculador->calcular(2, 1, 5, 150.0, true);

        $this->assertSame(0, $resultado['porcentaje']);
        $this->assertStringContainsString('injustificada', $resultado['motivo']);
    }
}
