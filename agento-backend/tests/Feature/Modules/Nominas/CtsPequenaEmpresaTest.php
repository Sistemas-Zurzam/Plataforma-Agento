<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Services\BeneficioSocialService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * CTS de noviembre en una pequeña empresa REMYPE con el criterio de RR.HH.:
 * base = sueldo + 1/6 gratificación de julio + promedio (suma/6) de horas extra
 * y comisiones percibidas en 3 meses o más, incluidas las de complementarias;
 * días comerciales sin descontar faltas; 50% por pequeña empresa. Datos ficticios.
 */
class CtsPequenaEmpresaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    /** @var array<string, CicloRemunerativo> */
    private array $ciclos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::factory()->create(['regimen_laboral' => 'Pequeña Empresa', 'inscrita_remype' => true, 'fecha_inscripcion_remype' => '2020-01-01']);
        foreach (['05', '06', '07', '08', '09', '10'] as $mes) {
            $fin = "2026-$mes-".cal_days_in_month(CAL_GREGORIAN, (int) $mes, 2026);
            $this->ciclos[$mes] = CicloRemunerativo::create(['empresa_id' => $this->empresa->id, 'nombre' => "Mes $mes", 'periodicidad' => 'mensual',
                'fecha_inicio' => "2026-$mes-01", 'fecha_fin' => $fin, 'fecha_corte_asistencia' => $fin, 'fecha_pago' => $fin, 'estado' => 'pagado']);
        }
    }

    /** @param  array<string, array<string, float>>  $variables  codigo => [mes => monto] en la boleta ordinaria */
    private function colaborador(string $nombre, string $ingreso, float $sueldo, array $variables = [], array $atributos = [], string $regimen = 'Pequeña Empresa'): Colaborador
    {
        $c = $this->crearColaborador($this->empresa, ['nombres' => $nombre, 'apellidos' => 'Prueba', 'fecha_ingreso' => $ingreso, 'regimen_laboral' => $regimen, ...$atributos]);
        $c->remuneraciones()->update(['salario' => $sueldo, 'vigencia_desde' => $ingreso]);
        $c->condicionesLaborales()->delete();
        ColaboradorCondicionLaboral::create(['colaborador_id' => $c->id, 'regimen_laboral' => $regimen, 'vigencia_desde' => $ingreso]);

        foreach ($this->ciclos as $mes => $ciclo) {
            if ($ciclo->fecha_fin->lt($c->fecha_ingreso)) {
                continue;
            }
            $b = Boleta::create(['ciclo_id' => $ciclo->id, 'empresa_id' => $this->empresa->id, 'colaborador_id' => $c->id, 'version' => 1, 'es_version_vigente' => true,
                'regimen_laboral_snapshot' => $regimen, 'sueldo_basico_snapshot' => $sueldo, 'dias_pagados' => 30, 'total_ingresos' => $sueldo, 'total_egresos' => 0,
                'total_aportaciones' => 0, 'neto_a_pagar' => $sueldo, 'estado' => 'pagada', 'snapshot_parametros_version' => 't', 'snapshot_reglas_version' => 't', 'calculado_at' => now()]);
            $this->linea($b, 'SUELDO_BASICO', $sueldo);
            foreach ($variables as $codigo => $porMes) {
                if (isset($porMes[$mes])) {
                    $this->linea($b, $codigo, $porMes[$mes]);
                }
            }
        }

        return $c;
    }

    private function linea(Boleta $b, string $codigo, float $monto): void
    {
        BoletaConcepto::create(['boleta_id' => $b->id, 'concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id, 'tipo' => 'ingreso',
            'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'monto' => $monto]);
    }

    private function gratificacionJulio(Colaborador $c, float $bruta): void
    {
        $b = BeneficioSocial::firstOrCreate(['empresa_id' => $this->empresa->id, 'tipo' => 'gratificacion_julio', 'anio' => 2026, 'version' => 1],
            ['es_version_vigente' => true, 'total_colaboradores' => 1, 'total_bruto' => 0, 'total_neto' => 0, 'estado' => 'pagado', 'calculado_at' => '2026-07-10', 'pagado_at' => '2026-07-15']);
        BeneficioSocialDetalle::create(['beneficio_social_id' => $b->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 0, 'meses' => 6, 'bruta' => $bruta, 'bonificacion_extraordinaria' => 0, 'neta' => $bruta]);
    }

    /**
     * Complementaria pagada sobre la boleta del mes. Su snapshot es acumulativo:
     * repite las líneas agregadas por complementarias anteriores.
     *
     * @param  array<int, float>  $agregadas
     */
    private function complementaria(Colaborador $c, string $mes, array $agregadas, string $codigo = 'COMISION'): void
    {
        $boleta = Boleta::where('colaborador_id', $c->id)->where('ciclo_id', $this->ciclos[$mes]->id)->firstOrFail();
        $item = PlanillaComplementaria::create(['empresa_id' => $this->empresa->id, 'ciclo_id' => $this->ciclos[$mes]->id, 'nombre' => "Complementaria $mes", 'motivo' => 'Prueba', 'estado' => 'pagada', 'pagado_at' => now()]);
        $ingresos = [['codigo' => 'SUELDO_BASICO', 'tipo' => 'ingreso', 'monto' => (float) $boleta->sueldo_basico_snapshot]];
        foreach ($agregadas as $monto) {
            $ingresos[] = ['codigo' => $codigo, 'tipo' => 'ingreso', 'monto' => $monto, 'agregado_por' => 1, 'agregado_en' => '2026-11-01 10:00:00'];
        }
        PlanillaComplementariaDetalle::create(['planilla_complementaria_id' => $item->id, 'boleta_original_id' => $boleta->id, 'colaborador_id' => $c->id,
            'neto_original' => 0, 'neto_recalculado' => 0, 'diferencia_ingresos' => 0, 'diferencia_egresos' => 0, 'diferencia_aportaciones' => 0, 'diferencia_neta' => 0,
            'calculo_snapshot' => ['ingresos' => $ingresos, 'egresos' => [], 'aportaciones' => []]]);
    }

    /** @return array<string, array<string, mixed>> fila por nombre */
    private function filas(): array
    {
        return collect(app(BeneficioSocialService::class)->resumen($this->empresa, 'cts_noviembre', 2026)['colaboradores'])
            ->keyBy(fn ($f) => explode(' ', $f['colaborador'])[0])->all();
    }

    public function test_pequena_empresa_deposita_el_50_por_ciento_con_sexto_de_gratificacion(): void
    {
        $c = $this->colaborador('Ana', '2026-01-01', 1200);
        $this->gratificacionJulio($c, 600);

        $f = $this->filas()['Ana'];

        // (1,200 + 600/6) / 360 x 180 x 50% = 325.00
        $this->assertSame(0.5, $f['tasa']);
        $this->assertSame(180, $f['dias_computables']);
        $this->assertSame(325.0, $f['neta']);
        $this->assertSame('calculado', $f['estado'], implode(' | ', $f['alertas']));
    }

    public function test_promedia_variables_de_3_meses_o_mas_incluidas_las_de_complementarias(): void
    {
        // Ingresa el 1/06: 150 días. HE en 4 meses (boleta). Comisión en julio (boleta) y ago-oct (complementarias).
        $c = $this->colaborador('Beto', '2026-06-01', 1500, ['HE_25' => ['07' => 120, '08' => 60, '09' => 60, '10' => 60], 'COMISION' => ['07' => 300]]);
        $this->gratificacionJulio($c, 150);
        $this->complementaria($c, '08', [600]);
        $this->complementaria($c, '09', [300]);
        $this->complementaria($c, '10', [600]);
        // Comisiones en solo 2 meses: no se promedian.
        $d = $this->colaborador('Dora', '2026-05-01', 1000, ['COMISION' => ['06' => 900, '09' => 900]]);
        $this->gratificacionJulio($d, 500);

        $f = $this->filas();

        $this->assertSame(['2026-07' => 300.0, '2026-08' => 600.0, '2026-09' => 300.0, '2026-10' => 600.0], $f['Beto']['variables']['COMISION']['importes_mensuales']);
        $this->assertSame(300.0, $f['Beto']['variables']['COMISION']['incluido']);   // 1,800 / 6
        $this->assertSame(50.0, $f['Beto']['variables']['HE_25']['incluido']);        // 300 / 6
        // (1,500 + 25 + 50 + 300) / 360 x 150 x 50% = 390.63
        $this->assertSame(150, $f['Beto']['dias_computables']);
        $this->assertSame(390.63, $f['Beto']['neta']);
        $this->assertSame('calculado', $f['Beto']['estado'], implode(' | ', $f['Beto']['alertas']));

        $this->assertSame(0.0, $f['Dora']['variables']['COMISION']['incluido']);
        $this->assertSame(round((1000 + round(500 / 6, 2)) / 360 * 180 * 0.5, 2), $f['Dora']['neta']);
    }

    public function test_complementarias_acumulativas_no_duplican_lo_agregado(): void
    {
        $c = $this->colaborador('Eva', '2026-05-01', 1000, ['COMISION' => ['05' => 100, '06' => 100]]);
        $this->gratificacionJulio($c, 500);
        // Dos complementarias pagadas sobre agosto: la segunda arrastra la comisión de la primera.
        $this->complementaria($c, '08', [400]);
        $this->complementaria($c, '08', [400, 200]);

        $comision = $this->filas()['Eva']['variables']['COMISION'];

        $this->assertSame(['2026-05' => 100.0, '2026-06' => 100.0, '2026-08' => 600.0], $comision['importes_mensuales']);
        $this->assertSame(round(800 / 6, 2), $comision['incluido']);
    }

    public function test_las_faltas_no_descuentan_dias_y_cuenta_meses_comerciales(): void
    {
        $c = $this->colaborador('Gina', '2026-09-16', 1800);
        $r = \App\Modules\Asistencia\Models\AsistenciaResultadoDiario::create(['empresa_id' => $this->empresa->id, 'colaborador_id' => $c->id,
            'fecha' => '2026-10-05', 'tipo_dia' => 'laborable', 'estado' => 'falta', 'procesado_at' => now()]);
        \App\Modules\Asistencia\Models\AsistenciaIncidencia::create(['empresa_id' => $this->empresa->id, 'resultado_diario_id' => $r->id,
            'colaborador_id' => $c->id, 'fecha' => '2026-10-05', 'tipo' => 'falta', 'estado' => 'rechazada']);

        $f = $this->filas()['Gina'];

        // 16-30 sep (15) + oct (30) = 45 días; 1,800 / 360 x 45 x 50% = 112.50. Ingresó después de junio: sin gratificación de julio.
        $this->assertSame(45, $f['dias_computables']);
        $this->assertSame(112.5, $f['neta']);
        $this->assertSame('calculado', $f['estado'], implode(' | ', $f['alertas']));
    }

    public function test_no_incluye_locadores_cesados_ni_menos_de_un_mes(): void
    {
        $activo = $this->colaborador('Activo', '2026-01-01', 1400);
        $this->gratificacionJulio($activo, 700);
        $this->colaborador('Locador', '2026-01-01', 5000, [], [], 'Locacion de Servicios');
        $this->colaborador('Cesado', '2026-01-01', 1400, [], ['activo' => false, 'fecha_cese' => '2026-09-30', 'motivo_cese' => 'Renuncia']);
        $this->colaborador('Nuevo', '2026-10-15', 1400);

        $this->assertSame(['Activo'], array_keys($this->filas()));
    }

    public function test_avisa_si_falta_la_gratificacion_de_julio(): void
    {
        $this->colaborador('Hugo', '2026-02-01', 1500);

        $f = $this->filas()['Hugo'];

        $this->assertSame('observado', $f['estado']);
        $this->assertStringContainsString('Gratificacion de julio 2026 no registrada', implode(' | ', $f['alertas']));
    }

    public function test_sin_sustento_remype_queda_en_revision(): void
    {
        $this->empresa->update(['inscrita_remype' => false, 'fecha_inscripcion_remype' => null]);
        $c = $this->colaborador('Ines', '2026-01-01', 1200);
        $this->gratificacionJulio($c, 600);

        $f = $this->filas()['Ines'];

        $this->assertSame('observado', $f['estado']);
        $this->assertStringContainsString('REMYPE', implode(' | ', $f['alertas']));
    }
}
