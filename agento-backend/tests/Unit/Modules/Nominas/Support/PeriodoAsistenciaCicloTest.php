<?php

namespace Tests\Unit\Modules\Nominas\Support;

use App\Modules\Nominas\Support\PeriodoAsistenciaCiclo;
use PHPUnit\Framework\TestCase;

class PeriodoAsistenciaCicloTest extends TestCase
{
    public function test_un_corte_27_usa_el_tramo_del_28_anterior_al_27_actual(): void
    {
        $periodo = PeriodoAsistenciaCiclo::resolver('2026-09-01', '2026-09-30', '2026-09-27');

        $this->assertSame(['inicio' => '2026-08-28', 'fin' => '2026-09-27'], $periodo);
    }

    public function test_un_corte_del_27_permite_horas_extra_desde_el_mes_anterior(): void
    {
        $periodo = PeriodoAsistenciaCiclo::resolver('2026-10-01', '2026-10-31', '2026-10-27');

        $this->assertSame(['inicio' => '2026-09-28', 'fin' => '2026-10-27'], $periodo);
    }

    public function test_horas_extra_manual_tambien_permiten_el_dia_27_del_mes_anterior(): void
    {
        $periodo = PeriodoAsistenciaCiclo::resolverHorasExtraManual('2026-10-01', '2026-10-31', '2026-10-27');

        $this->assertSame(['inicio' => '2026-09-27', 'fin' => '2026-10-27'], $periodo);
    }

    public function test_un_corte_al_final_conserva_el_mes_calendario(): void
    {
        $periodo = PeriodoAsistenciaCiclo::resolver('2026-09-01', '2026-09-30', '2026-09-30');

        $this->assertSame(['inicio' => '2026-09-01', 'fin' => '2026-09-30'], $periodo);
    }

    public function test_el_proyectado_recorta_la_lectura_al_ultimo_dia_procesado(): void
    {
        $periodo = PeriodoAsistenciaCiclo::acotar(['inicio' => '2026-10-01', 'fin' => '2026-10-31'], '2026-10-22');

        $this->assertSame(['inicio' => '2026-10-01', 'fin' => '2026-10-22'], $periodo);
    }

    public function test_acotar_nunca_amplia_el_periodo_ni_cambia_sin_fin_de_asistencia(): void
    {
        $periodo = ['inicio' => '2026-08-28', 'fin' => '2026-09-27'];

        $this->assertSame($periodo, PeriodoAsistenciaCiclo::acotar($periodo, '2026-09-30'));
        $this->assertSame($periodo, PeriodoAsistenciaCiclo::acotar($periodo, null));
    }
}
