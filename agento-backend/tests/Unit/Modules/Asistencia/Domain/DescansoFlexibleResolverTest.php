<?php

namespace Tests\Unit\Modules\Asistencia\Domain;

use App\Modules\Asistencia\Domain\DescansoFlexibleResolver;
use PHPUnit\Framework\TestCase;

class DescansoFlexibleResolverTest extends TestCase
{
    public function test_primer_candidato_es_descanso_y_el_siguiente_falta(): void
    {
        $dias = [
            ['fecha' => '2026-08-31', 'esCandidato' => false], // lunes, trabajó
            ['fecha' => '2026-09-01', 'esCandidato' => true],  // martes, sin marcación
            ['fecha' => '2026-09-02', 'esCandidato' => false], // miércoles, trabajó
            ['fecha' => '2026-09-03', 'esCandidato' => true],  // jueves, sin marcación
            ['fecha' => '2026-09-04', 'esCandidato' => false], // viernes, trabajó
            ['fecha' => '2026-09-05', 'esCandidato' => false], // sábado, trabajó
            ['fecha' => '2026-09-06', 'esCandidato' => false], // domingo, trabajó
        ];

        $veredictos = DescansoFlexibleResolver::resolver($dias, 1);

        $this->assertSame([
            '2026-09-01' => 'descanso',
            '2026-09-03' => 'falta',
        ], $veredictos);
    }

    public function test_segmentar_la_semana_en_dos_llamadas_da_el_mismo_resultado_que_evaluarla_completa(): void
    {
        $semanaCompleta = [
            ['fecha' => 'lun', 'esCandidato' => true],
            ['fecha' => 'mar', 'esCandidato' => false],
            ['fecha' => 'mie', 'esCandidato' => false],
            ['fecha' => 'jue', 'esCandidato' => false],
            ['fecha' => 'vie', 'esCandidato' => true],
            ['fecha' => 'sab', 'esCandidato' => true],
            ['fecha' => 'dom', 'esCandidato' => false],
        ];

        $resultadoDeUnaSola = DescansoFlexibleResolver::resolver($semanaCompleta, 2);

        // Mismo escenario partido en 2 segmentos (Lun-Mié / Jue-Dom), como
        // ocurre cuando la semana cruza el límite de dos periodos.
        $segmento1 = array_slice($semanaCompleta, 0, 3);
        $veredictosSegmento1 = DescansoFlexibleResolver::resolver($segmento1, 2);

        $yaAsignados = count(array_filter($veredictosSegmento1, fn ($v) => $v === 'descanso'));
        $segmento2 = array_slice($semanaCompleta, 3);
        $veredictosSegmento2 = DescansoFlexibleResolver::resolver($segmento2, 2 - $yaAsignados);

        $this->assertSame($resultadoDeUnaSola, $veredictosSegmento1 + $veredictosSegmento2);
    }

    public function test_sin_candidatos_no_devuelve_ningun_veredicto(): void
    {
        $dias = [
            ['fecha' => '2026-09-01', 'esCandidato' => false],
            ['fecha' => '2026-09-02', 'esCandidato' => false],
        ];

        $this->assertSame([], DescansoFlexibleResolver::resolver($dias, 2));
    }

    public function test_remanente_cero_convierte_todos_los_candidatos_en_falta(): void
    {
        $dias = [
            ['fecha' => '2026-09-01', 'esCandidato' => true],
            ['fecha' => '2026-09-02', 'esCandidato' => true],
        ];

        $this->assertSame([
            '2026-09-01' => 'falta',
            '2026-09-02' => 'falta',
        ], DescansoFlexibleResolver::resolver($dias, 0));
    }

    public function test_multiples_descansos_requeridos_en_un_solo_ciclo(): void
    {
        $dias = [
            ['fecha' => '2026-09-01', 'esCandidato' => true],
            ['fecha' => '2026-09-02', 'esCandidato' => true],
            ['fecha' => '2026-09-03', 'esCandidato' => true],
        ];

        $this->assertSame([
            '2026-09-01' => 'descanso',
            '2026-09-02' => 'descanso',
            '2026-09-03' => 'falta',
        ], DescansoFlexibleResolver::resolver($dias, 2));
    }
}
