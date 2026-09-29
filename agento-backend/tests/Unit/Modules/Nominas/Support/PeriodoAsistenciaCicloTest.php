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

    public function test_un_corte_al_final_conserva_el_mes_calendario(): void
    {
        $periodo = PeriodoAsistenciaCiclo::resolver('2026-09-01', '2026-09-30', '2026-09-30');

        $this->assertSame(['inicio' => '2026-09-01', 'fin' => '2026-09-30'], $periodo);
    }
}
