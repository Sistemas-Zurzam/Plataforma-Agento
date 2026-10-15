<?php

namespace Tests\Unit\Modules\Nominas;

use App\Modules\Nominas\Domain\Liquidacion\CalculadoraBeneficiosCese;
use App\Modules\Nominas\Domain\Liquidacion\DiasComerciales;
use App\Modules\Nominas\Support\ProrateoCese;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Reglas de cálculo de cese y liquidación sin base de datos. Los montos
 * esperados están calculados a mano en cada caso.
 */
class BeneficiosCeseTest extends TestCase
{
    public function test_dias_comerciales_usan_meses_de_30_dias(): void
    {
        $this->assertSame(180, DiasComerciales::entre(Carbon::parse('2026-05-01'), Carbon::parse('2026-10-31')));
        $this->assertSame(30, DiasComerciales::entre(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28')));
        $this->assertSame(1, DiasComerciales::entre(Carbon::parse('2026-01-31'), Carbon::parse('2026-01-31')));
        $this->assertSame(2, DiasComerciales::entre(Carbon::parse('2026-02-28'), Carbon::parse('2026-03-01')));
        $this->assertSame(360, DiasComerciales::entre(Carbon::parse('2025-01-01'), Carbon::parse('2025-12-31')));
        $this->assertSame(0, DiasComerciales::entre(Carbon::parse('2026-03-02'), Carbon::parse('2026-03-01')));
    }

    public function test_prorrateo_de_cese_paga_hasta_la_fecha_de_cese(): void
    {
        $this->assertSame(0.0, ProrateoCese::diasNoPagados(null, '2026-09-01', '2026-09-30'));
        $this->assertSame(15.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-09-15'), '2026-09-01', '2026-09-30'));
        $this->assertSame(0.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-09-30'), '2026-09-01', '2026-09-30'));
        // Cese el último día de febrero: mes completo (30 días).
        $this->assertSame(0.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-02-28'), '2026-02-01', '2026-02-28'));
        $this->assertSame(16.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-02-14'), '2026-02-01', '2026-02-28'));
        // Cese el 31: el tope de 30 días evita pagar un día de más.
        $this->assertSame(0.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-10-31'), '2026-10-01', '2026-10-31'));
        // Cese posterior al ciclo: el ciclo se paga completo.
        $this->assertSame(0.0, ProrateoCese::diasNoPagados(Carbon::parse('2026-11-05'), '2026-10-01', '2026-10-31'));
    }

    private function datos(array $cambios = []): array
    {
        return [
            'fecha_ingreso' => Carbon::parse('2024-03-01'), 'fecha_cese' => Carbon::parse('2026-09-15'),
            'sueldo' => 3000.0, 'asignacion_familiar' => 113.0,
            'gratificacion_percibida_cts' => 3113.0, 'gratificacion_semestre_pagada' => false,
            'dias_vacaciones_gozados' => 30.0, 'ajuste_vacaciones' => 0.0,
            'tasa_gratificacion' => 1.0, 'tasa_bonificacion_extraordinaria' => 0.09, 'tasa_cts' => 1.0, 'vacaciones_dias' => 30,
            ...$cambios,
        ];
    }

    private function monto(array $resultado, string $codigo): ?float
    {
        $linea = collect($resultado['conceptos'])->firstWhere('codigo', $codigo);

        return $linea ? $linea['monto'] : null;
    }

    public function test_beneficios_incluyen_asignacion_familiar_y_meses_de_30_dias(): void
    {
        $r = CalculadoraBeneficiosCese::calcular($this->datos());

        $this->assertSame(3113.0, $r['remuneracion_computable']);
        // Gratificación: julio y agosto completos → 3113 / 6 × 2.
        $this->assertSame(1037.67, $this->monto($r, 'GRATIFICACION_TRUNCA'));
        $this->assertSame(93.39, $this->monto($r, 'BONIFICACION_EXTRAORDINARIA_TRUNCA'));
        // CTS: 01/05 al 15/09 = 135 días comerciales; base 3113 + 3113/6.
        $this->assertSame(1361.94, $this->monto($r, 'CTS_TRUNCA'));
        // 915 días de servicio: 2 récords completos + 195 días (16.25 días truncos).
        $this->assertSame(1686.21, $this->monto($r, 'VACACIONES_TRUNCAS'));
        // Gozó 30: queda el 2.º récord, todavía dentro de su plazo.
        $this->assertSame(3113.0, $this->monto($r, 'VACACIONES_PENDIENTES'));
        $this->assertNull($this->monto($r, 'INDEMNIZACION_VACACIONAL'));
    }

    public function test_record_vencido_no_gozado_genera_indemnizacion_vacacional(): void
    {
        $r = CalculadoraBeneficiosCese::calcular($this->datos(['dias_vacaciones_gozados' => 0.0]));

        $this->assertSame(6226.0, $this->monto($r, 'VACACIONES_PENDIENTES'));
        // El 1.er récord (ganado en marzo 2025) venció en marzo 2026 sin gozarse.
        $this->assertSame(3113.0, $this->monto($r, 'INDEMNIZACION_VACACIONAL'));
    }

    public function test_ajuste_de_kardex_sin_anio_completo_no_genera_indemnizacion(): void
    {
        $r = CalculadoraBeneficiosCese::calcular($this->datos([
            'fecha_ingreso' => Carbon::parse('2026-03-01'), 'dias_vacaciones_gozados' => 0.0, 'ajuste_vacaciones' => 10.0,
        ]));

        $this->assertNull($this->monto($r, 'INDEMNIZACION_VACACIONAL'));
        // 01/03 al 15/09 = 195 días → 16.25 truncos; los 10 del ajuste quedan como no gozados.
        $this->assertSame(1686.21, $this->monto($r, 'VACACIONES_TRUNCAS'));
        $this->assertSame(1037.67, $this->monto($r, 'VACACIONES_PENDIENTES'));
    }

    public function test_cese_el_dia_31_no_desborda_el_inicio_del_semestre_de_cts(): void
    {
        // Cese 31/01/2027: el semestre de CTS empezó el 01/11/2026 → nov, dic y ene completos = 90 días.
        $r = CalculadoraBeneficiosCese::calcular($this->datos(['fecha_cese' => Carbon::parse('2027-01-31')]));
        $cts = collect($r['conceptos'])->firstWhere('codigo', 'CTS_TRUNCA');

        $this->assertSame(90, $cts['cantidad']);
    }

    public function test_gratificacion_ya_pagada_y_menos_de_un_mes_no_generan_beneficio(): void
    {
        $r = CalculadoraBeneficiosCese::calcular($this->datos(['gratificacion_semestre_pagada' => true]));
        $this->assertSame(0.0, $this->monto($r, 'GRATIFICACION_TRUNCA'));

        $corto = CalculadoraBeneficiosCese::calcular($this->datos([
            'fecha_ingreso' => Carbon::parse('2026-09-01'), 'fecha_cese' => Carbon::parse('2026-09-20'), 'dias_vacaciones_gozados' => 0.0,
        ]));
        $this->assertSame(0.0, $this->monto($corto, 'CTS_TRUNCA'));
        $this->assertSame(0.0, $this->monto($corto, 'GRATIFICACION_TRUNCA'));
    }
}
