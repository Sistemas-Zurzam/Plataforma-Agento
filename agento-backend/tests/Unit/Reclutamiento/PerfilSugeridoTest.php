<?php

namespace Tests\Unit\Reclutamiento;

use App\Modules\Reclutamiento\Domain\PerfilSugerido;
use PHPUnit\Framework\TestCase;

class PerfilSugeridoTest extends TestCase
{
    public function test_cada_linea_es_un_requisito_y_solo_lo_indispensable_es_excluyente(): void
    {
        $texto = "- Experiencia en diseño gráfico.\n\n• Manejo de Canva, Photoshop y/o Illustrator.\n1. Presentar portafolio (requisito indispensable).\nManejo de canva, photoshop y/o illustrator.\n  ";

        $this->assertSame([
            ['tipo' => 'deseable', 'descripcion' => 'Experiencia en diseño gráfico.'],
            ['tipo' => 'deseable', 'descripcion' => 'Manejo de Canva, Photoshop y/o Illustrator.'],
            ['tipo' => 'excluyente', 'descripcion' => 'Presentar portafolio (requisito indispensable).'],
        ], PerfilSugerido::requisitos($texto));
    }

    public function test_sin_texto_no_hay_requisitos_y_se_respetan_los_limites(): void
    {
        $this->assertSame([], PerfilSugerido::requisitos(null));
        $this->assertSame([], PerfilSugerido::requisitos("  \n \n"));

        $muchos = implode("\n", array_map(fn ($i) => "Requisito {$i} ".str_repeat('x', 400), range(1, 40)));
        $requisitos = PerfilSugerido::requisitos($muchos);
        $this->assertCount(PerfilSugerido::MAX_REQUISITOS, $requisitos);
        $this->assertSame(300, mb_strlen($requisitos[0]['descripcion']));
    }

    public function test_competencias_reparten_pesos_hasta_100(): void
    {
        $competencias = PerfilSugerido::competencias([
            ['nombre' => 'Photoshop', 'nivel' => '4'], ['nombre' => 'Canva', 'nivel' => 3], ['nombre' => 'Illustrator', 'nivel' => 3],
        ]);

        $this->assertSame([34, 33, 33], array_column($competencias, 'peso'));
        $this->assertSame(4, $competencias[0]['nivel']);
        $this->assertSame([], PerfilSugerido::competencias([]));
    }
}
