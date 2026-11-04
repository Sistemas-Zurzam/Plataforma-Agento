<?php

namespace Tests\Unit\Modules\Reclutamiento\Domain;

use App\Modules\Reclutamiento\Domain\CalculadoraPuntajeFinal;
use PHPUnit\Framework\TestCase;

class CalculadoraPuntajeFinalTest extends TestCase
{
    private const PESOS = ['ats' => 40, 'psicometria' => 30, 'entrevistas' => 30];

    public function test_todos_completos_da_media_ponderada_definitiva(): void
    {
        $resultado = (new CalculadoraPuntajeFinal)->calcular(self::PESOS, [
            'ats' => ['estado' => 'completo', 'valor' => 80.0],
            'psicometria' => ['estado' => 'completo', 'valor' => 60.0],
            'entrevistas' => ['estado' => 'completo', 'valor' => 50.0],
        ]);

        // 0.4×80 + 0.3×60 + 0.3×50 = 32 + 18 + 15 = 65
        $this->assertSame(65.0, $resultado['puntaje']);
        $this->assertFalse($resultado['provisional']);
    }

    public function test_componente_pendiente_no_se_convierte_en_cero_y_marca_provisional(): void
    {
        $resultado = (new CalculadoraPuntajeFinal)->calcular(self::PESOS, [
            'ats' => ['estado' => 'completo', 'valor' => 80.0],
            'psicometria' => ['estado' => 'pendiente', 'valor' => null],
            'entrevistas' => ['estado' => 'completo', 'valor' => 50.0],
        ]);

        // Con psicometría en 0 daría 47; se calcula con lo disponible:
        // (40×80 + 30×50) / 70 = 67.14
        $this->assertSame(67.14, $resultado['puntaje']);
        $this->assertTrue($resultado['provisional']);
    }

    public function test_no_aplica_redistribuye_pesos_proporcionalmente(): void
    {
        $resultado = (new CalculadoraPuntajeFinal)->calcular(self::PESOS, [
            'ats' => ['estado' => 'completo', 'valor' => 80.0],
            'psicometria' => ['estado' => 'no_aplica', 'valor' => null],
            'entrevistas' => ['estado' => 'completo', 'valor' => 50.0],
        ]);

        $this->assertEqualsWithDelta(57.1429, $resultado['pesos_efectivos']['ats'], 0.0001);
        $this->assertEqualsWithDelta(42.8571, $resultado['pesos_efectivos']['entrevistas'], 0.0001);
        $this->assertArrayNotHasKey('psicometria', $resultado['pesos_efectivos']);
        $this->assertSame(67.14, $resultado['puntaje']);
        $this->assertFalse($resultado['provisional']);
    }

    public function test_sin_componentes_completos_no_hay_puntaje(): void
    {
        $resultado = (new CalculadoraPuntajeFinal)->calcular(self::PESOS, [
            'ats' => ['estado' => 'pendiente', 'valor' => null],
            'psicometria' => ['estado' => 'pendiente', 'valor' => null],
            'entrevistas' => ['estado' => 'pendiente', 'valor' => null],
        ]);

        $this->assertNull($resultado['puntaje']);
        $this->assertTrue($resultado['provisional']);
    }

    public function test_normalizacion_de_entrevista(): void
    {
        $this->assertSame(['promedio' => 3.0, 'puntaje' => 50.0], CalculadoraPuntajeFinal::normalizarEntrevista([2, 3, 4]));
        $this->assertSame(['promedio' => 5.0, 'puntaje' => 100.0], CalculadoraPuntajeFinal::normalizarEntrevista([5, 5]));
        $this->assertSame(['promedio' => 1.0, 'puntaje' => 0.0], CalculadoraPuntajeFinal::normalizarEntrevista([1]));
    }

    public function test_calificacion_fuera_de_escala_es_rechazada(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalculadoraPuntajeFinal::normalizarEntrevista([6]);
    }
}
