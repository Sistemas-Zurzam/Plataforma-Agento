<?php

namespace Tests\Unit\Modules\Nominas\Support;

use App\Modules\Nominas\Support\ProrateoRango;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ProrateoRangoTest extends TestCase
{
    public function test_un_mes_calendario_completo_vale_30_dias_aun_en_meses_de_31_o_febrero(): void
    {
        $this->assertSame(30, ProrateoRango::diasDelRango('2026-10-01', '2026-10-31'));
        $this->assertSame(30, ProrateoRango::diasDelRango('2026-02-01', '2026-02-28'));
        $this->assertSame(0.0, ProrateoRango::diasNoPagados('2026-10-01', '2026-10-31'));
    }

    public function test_una_quincena_paga_15_dias_y_no_paga_los_otros_15(): void
    {
        $this->assertSame(15, ProrateoRango::diasDelRango('2026-10-01', '2026-10-15'));
        $this->assertSame(15.0, ProrateoRango::diasNoPagados('2026-10-01', '2026-10-15'));
    }

    public function test_un_rango_entre_meses_cuenta_dias_calendario_hasta_30(): void
    {
        $this->assertSame(30, ProrateoRango::diasDelRango('2026-10-16', '2026-11-15'));
        $this->assertSame(10, ProrateoRango::diasDelRango('2026-10-16', '2026-10-25'));
    }

    public function test_si_cesa_dentro_del_rango_no_descuenta_para_no_duplicar_prorateo_cese(): void
    {
        $this->assertSame(0.0, ProrateoRango::diasNoPagados('2026-10-01', '2026-10-15', Carbon::parse('2026-10-10')));
        $this->assertSame(15.0, ProrateoRango::diasNoPagados('2026-10-01', '2026-10-15', Carbon::parse('2026-10-20')));
    }
}
