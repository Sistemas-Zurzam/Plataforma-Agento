<?php

namespace Tests\Unit\Reclutamiento;

use App\Modules\Reclutamiento\Application\ImportadorPrueba;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportadorPruebaTest extends TestCase
{
    public function test_json_conserva_escala_validez_interpretacion_y_tipos_de_puesto(): void
    {
        ['contenido' => $c, 'advertencias' => $avisos] = (new ImportadorPrueba)->desdeJson([
            'nombre' => 'Personalidad',
            'escala' => ['min' => 1, 'max' => 5, 'etiquetas' => ['1' => 'TD', '2' => 'D', '3' => 'N', '4' => 'A', '5' => 'TA']],
            'dimensiones' => [
                ['codigo' => 'RES', 'nombre' => 'Responsabilidad', 'interpretacion' => ['alto' => 'Cumple plazos', 'bajo' => 'Descuida plazos', 'pregunta_entrevista' => '¿Cómo se organizó?']],
                ['codigo' => 'DES', 'nombre' => 'Deseabilidad social'],
            ],
            'puntuacion' => ['niveles' => ['medio' => [13, 19], 'alto' => [20, 25]], 'validez' => ['dimension' => 'DES', 'alerta_desde' => 17]],
            'perfiles_puesto' => [['nombre' => 'Administrativo', 'dimensiones' => ['RES', 'DES', 'XYZ']]],
            'preguntas' => [
                ['id' => 2, 'dimension' => 'RES', 'texto' => 'Dejo todo para el final.', 'invertida' => true],
                ['id' => 1, 'dimension' => 'RES', 'texto' => 'Cumplo los plazos.', 'invertida' => false],
                ['id' => 3, 'dimension' => 'DES', 'texto' => 'Nunca me equivoco.'],
            ],
        ]);

        $this->assertSame([], $avisos);
        $this->assertSame('psicometrica', $c['tipo']);
        $this->assertSame(['TD', 'D', 'N', 'A', 'TA'], array_column($c['escala'], 'etiqueta'));
        $this->assertSame(['Cumplo los plazos.', 'Dejo todo para el final.', 'Nunca me equivoco.'], array_column($c['preguntas'], 'enunciado'), 'ordenadas por id');
        $this->assertSame([false, true, false], array_column($c['preguntas'], 'invertida'));
        $this->assertSame(['escala', 'escala', 'escala'], array_column($c['preguntas'], 'tipo'));

        [$res, $des] = $c['dimensiones'];
        $this->assertSame([1, 13.0, 20.0, '¿Cómo se organizó?'], [$res['peso'], $res['nivel_medio_desde'], $res['nivel_alto_desde'], $res['pregunta_entrevista']]);
        $this->assertSame([0, true, 17.0], [$des['peso'], $des['es_validez'], $des['alerta_desde']]);
        $this->assertSame([['nombre' => 'Administrativo', 'dimensiones' => ['RES']]], $c['perfiles_puesto'], 'sin validez ni códigos inexistentes');
    }

    public function test_dimension_no_definida_se_crea_y_se_avisa(): void
    {
        ['contenido' => $c, 'advertencias' => $avisos] = (new ImportadorPrueba)->desdeJson([
            'nombre' => 'X',
            'dimensiones' => [],
            'preguntas' => [['id' => 1, 'dimension' => 'ZZZ', 'texto' => 'Afirmación']],
        ]);

        $this->assertSame(['ZZZ'], array_column($c['dimensiones'], 'codigo'));
        $this->assertCount(5, $c['escala'], 'sin escala se usa la de acuerdo 1–5');
        $this->assertCount(2, $avisos);
    }

    public function test_texto_pegado_quita_numeracion_y_detecta_invertidas(): void
    {
        ['contenido' => $c] = (new ImportadorPrueba)->desdeTexto("1. Prefiero trabajar sola(o). (R)\n• Ayudo a mis compañeros.\n\n12) Me adapto a los cambios.\nok");

        $this->assertSame(['Prefiero trabajar sola(o).', 'Ayudo a mis compañeros.', 'Me adapto a los cambios.'], array_column($c['preguntas'], 'enunciado'));
        $this->assertSame([true, false, false], array_column($c['preguntas'], 'invertida'));
        $this->assertNull($c['preguntas'][0]['dimension']);
    }

    public function test_texto_vacio_no_se_importa(): void
    {
        $this->expectException(ValidationException::class);

        (new ImportadorPrueba)->desdeTexto("\n  \n");
    }
}
