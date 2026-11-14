<?php

namespace Tests\Unit\Reclutamiento;

use App\Modules\Reclutamiento\Domain\PerfilPsicometrico;
use PHPUnit\Framework\TestCase;

class PerfilPsicometricoTest extends TestCase
{
    private const DIMENSIONES = [
        ['id' => 1, 'codigo' => 'RES', 'es_validez' => false, 'nivel_medio_desde' => 13.0, 'nivel_alto_desde' => 20.0, 'alerta_desde' => null],
        ['id' => 2, 'codigo' => 'SER', 'es_validez' => false, 'nivel_medio_desde' => 13.0, 'nivel_alto_desde' => 20.0, 'alerta_desde' => null],
        ['id' => 3, 'codigo' => 'DES', 'es_validez' => true, 'nivel_medio_desde' => null, 'nivel_alto_desde' => null, 'alerta_desde' => 17.0],
    ];

    public function test_niveles_segun_la_guia_bajo_5_12_medio_13_19_alto_20_25(): void
    {
        $this->assertSame('bajo', PerfilPsicometrico::nivel(12, 13, 20));
        $this->assertSame('medio', PerfilPsicometrico::nivel(13, 13, 20));
        $this->assertSame('medio', PerfilPsicometrico::nivel(19, 13, 20));
        $this->assertSame('alto', PerfilPsicometrico::nivel(20, 13, 20));
        $this->assertNull(PerfilPsicometrico::nivel(15, null, null), 'sin umbrales no se inventa un nivel');
    }

    public function test_validez_desde_17_marca_alerta_y_no_es_un_rasgo(): void
    {
        $con = PerfilPsicometrico::interpretar(self::DIMENSIONES, [1 => 22, 2 => 15, 3 => 17]);
        $this->assertTrue($con['validez']['alerta']);
        $this->assertArrayNotHasKey(3, $con['niveles']);

        $sin = PerfilPsicometrico::interpretar(self::DIMENSIONES, [1 => 22, 2 => 15, 3 => 16]);
        $this->assertFalse($sin['validez']['alerta']);
    }

    public function test_filtro_exige_el_nivel_minimo_en_las_prioritarias(): void
    {
        $sumas = [1 => 22, 2 => 12, 3 => 10]; // RES alto, SER bajo

        $medio = PerfilPsicometrico::interpretar(self::DIMENSIONES, $sumas, ['RES', 'SER'], 'medio');
        $this->assertFalse($medio['filtro']['cumple']);
        $this->assertSame(['SER'], $medio['filtro']['incumplidas']);

        $soloRes = PerfilPsicometrico::interpretar(self::DIMENSIONES, $sumas, ['RES'], 'alto');
        $this->assertTrue($soloRes['filtro']['cumple']);

        $this->assertNull(PerfilPsicometrico::interpretar(self::DIMENSIONES, $sumas)['filtro'], 'sin prioritarias no hay filtro');
    }

    public function test_prioritaria_sin_resultado_no_cumple(): void
    {
        $resultado = PerfilPsicometrico::interpretar(self::DIMENSIONES, [1 => 22], ['RES', 'SER'], 'medio');

        $this->assertFalse($resultado['filtro']['cumple']);
        $this->assertSame(['SER'], $resultado['filtro']['sin_resultado']);
    }
}
