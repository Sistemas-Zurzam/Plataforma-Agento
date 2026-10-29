<?php

namespace Tests\Unit\Modules\Reclutamiento\Domain;

use App\Modules\Reclutamiento\Domain\CalificadorPrueba;
use App\Modules\Reclutamiento\Domain\FlujoPostulacion;
use App\Modules\Reclutamiento\Domain\FlujoProceso;
use App\Modules\Reclutamiento\Domain\FlujoSolicitud;
use App\Modules\Reclutamiento\Domain\TransicionInvalidaException;
use PHPUnit\Framework\TestCase;

class FlujosTest extends TestCase
{
    public function test_solicitud_solo_se_acepta_si_fue_enviada(): void
    {
        $this->assertSame('aceptada', FlujoSolicitud::aplicar('enviada', 'aceptar'));

        $this->expectException(TransicionInvalidaException::class);
        FlujoSolicitud::aplicar('aceptada', 'aceptar');
    }

    public function test_devolver_y_rechazar_exigen_motivo(): void
    {
        $this->expectException(TransicionInvalidaException::class);
        FlujoSolicitud::aplicar('enviada', 'devolver', '');
    }

    public function test_cancelar_solicitud_aceptada_exige_motivo(): void
    {
        $this->assertSame('cancelada', FlujoSolicitud::aplicar('borrador', 'cancelar'));

        $this->expectException(TransicionInvalidaException::class);
        FlujoSolicitud::aplicar('aceptada', 'cancelar');
    }

    public function test_proceso_cerrado_no_se_puede_pausar(): void
    {
        $this->expectException(TransicionInvalidaException::class);
        FlujoProceso::aplicar('cerrado', 'pausar', 'motivo');
    }

    public function test_postulacion_no_activa_no_se_mueve(): void
    {
        $this->expectException(TransicionInvalidaException::class);
        FlujoPostulacion::mover('filtro_cv', 'descartada', 'psicometrica', null);
    }

    public function test_retroceder_etapa_exige_motivo(): void
    {
        FlujoPostulacion::mover('postulacion', 'activa', 'entrevista_virtual', null);

        $this->expectException(TransicionInvalidaException::class);
        FlujoPostulacion::mover('entrevista_virtual', 'activa', 'filtro_cv', null);
    }

    public function test_seleccionar_solo_en_etapa_seleccion_y_contratar_solo_seleccionada(): void
    {
        $this->assertSame('seleccionada', FlujoPostulacion::seleccionar('seleccion', 'activa'));
        $this->assertSame('contratada', FlujoPostulacion::contratar('seleccionada'));

        $this->expectException(TransicionInvalidaException::class);
        FlujoPostulacion::contratar('activa');
    }

    public function test_descarte_manual_no_acepta_causa_reservada_de_cancelacion(): void
    {
        $this->expectException(TransicionInvalidaException::class);
        FlujoPostulacion::descartar('activa', 'proceso_cancelado', 'motivo');
    }

    public function test_calificador_de_prueba_por_dimension_y_total(): void
    {
        $resultado = (new CalificadorPrueba)->calificar(
            [['id' => 1, 'peso' => 1], ['id' => 2, 'peso' => 3]],
            [
                ['id' => 10, 'dimension_id' => 1, 'peso' => 1, 'opciones' => [['id' => 100, 'valor' => 0], ['id' => 101, 'valor' => 4]]],
                ['id' => 11, 'dimension_id' => 2, 'peso' => 1, 'opciones' => [['id' => 110, 'valor' => 2], ['id' => 111, 'valor' => 4]]],
                ['id' => 12, 'dimension_id' => 2, 'peso' => 1, 'opciones' => [['id' => 120, 'valor' => 1], ['id' => 121, 'valor' => 4]]],
            ],
            [10 => 101, 11 => 110], // la pregunta 12 quedó sin responder
        );

        $this->assertSame(100.0, $resultado['dimensiones'][1]);
        $this->assertSame(25.0, $resultado['dimensiones'][2]); // (2 + 0) / (4 + 4)
        $this->assertSame(43.75, $resultado['puntaje']);       // (100×1 + 25×3) / 4
    }
}
