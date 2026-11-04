<?php

namespace Tests\Unit\Modules\Reclutamiento\Domain;

use App\Modules\Reclutamiento\Domain\CalculadoraAts;
use PHPUnit\Framework\TestCase;

class CalculadoraAtsTest extends TestCase
{
    private function competencias(?int $nivelExcel = 4, ?int $nivelIngles = 3): array
    {
        return [
            ['nombre' => 'Excel', 'nivel_requerido' => 4, 'peso' => 60, 'nivel_candidato' => $nivelExcel],
            ['nombre' => 'Inglés', 'nivel_requerido' => 3, 'peso' => 40, 'nivel_candidato' => $nivelIngles],
        ];
    }

    public function test_excluyente_incumplido_produce_cero_y_no_apto(): void
    {
        $resultado = (new CalculadoraAts)->calcular(
            [['id' => 1, 'tipo' => 'excluyente', 'resultado' => 'no_cumple']],
            $this->competencias(),
            60,
        );

        $this->assertSame(0.0, $resultado['puntaje']);
        $this->assertSame('no_apto', $resultado['resultado']);
    }

    public function test_excluyente_sin_informacion_requiere_revision_y_no_se_asume_incumplido(): void
    {
        $resultado = (new CalculadoraAts)->calcular(
            [['id' => 1, 'tipo' => 'excluyente', 'resultado' => 'sin_informacion']],
            $this->competencias(),
            60,
        );

        $this->assertSame('requiere_revision', $resultado['resultado']);
        $this->assertSame(100.0, $resultado['puntaje']);
    }

    public function test_competencia_sin_informacion_no_cuenta_como_cero(): void
    {
        $resultado = (new CalculadoraAts)->calcular([], $this->competencias(4, null), 60);

        // Solo se evalúa Excel (cobertura 1.0): no se penaliza Inglés desconocido.
        $this->assertSame(100.0, $resultado['puntaje']);
        $this->assertSame('requiere_revision', $resultado['resultado']);
    }

    public function test_sin_competencias_con_informacion_no_calcula_puntaje(): void
    {
        $resultado = (new CalculadoraAts)->calcular([], $this->competencias(null, null), 60);

        $this->assertNull($resultado['puntaje']);
        $this->assertSame('requiere_revision', $resultado['resultado']);
    }

    public function test_deseables_aportan_hasta_diez_puntos_sin_superar_cien(): void
    {
        $requisitos = [
            ['id' => 1, 'tipo' => 'excluyente', 'resultado' => 'cumple'],
            ['id' => 2, 'tipo' => 'deseable', 'resultado' => 'cumple'],
            ['id' => 3, 'tipo' => 'deseable', 'resultado' => 'cumple'],
        ];
        $resultado = (new CalculadoraAts)->calcular($requisitos, $this->competencias(5, 5), 60);

        $this->assertSame(100.0, $resultado['puntaje']);
        $this->assertSame('apto', $resultado['resultado']);
    }

    public function test_formula_ponderada_con_cobertura_parcial(): void
    {
        $requisitos = [
            ['id' => 2, 'tipo' => 'deseable', 'resultado' => 'cumple'],
            ['id' => 3, 'tipo' => 'deseable', 'resultado' => 'no_cumple'],
        ];
        // Excel 2/4 → 0.5 × 60 = 30; Inglés 3/3 → 1 × 40 = 40; cobertura 0.70
        // puntaje = 90 × 0.70 + 10 × 1/2 = 63 + 5 = 68
        $resultado = (new CalculadoraAts)->calcular($requisitos, $this->competencias(2, 3), 70);

        $this->assertSame(68.0, $resultado['puntaje']);
        $this->assertSame('bajo_umbral', $resultado['resultado']);
        $this->assertSame(CalculadoraAts::FORMULA, $resultado['desglose']['formula']);
    }

    public function test_normaliza_nombres_para_comparar(): void
    {
        $this->assertSame(CalculadoraAts::normalizar('  Inglés  Técnico '), CalculadoraAts::normalizar('ingles tecnico'));
    }
}
