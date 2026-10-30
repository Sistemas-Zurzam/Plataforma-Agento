<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class PagoHonorariosPorDiasPresentesTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_bonificacion_manual_se_suma_al_servicio_de_un_locador(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios',
            'fecha_ingreso' => '2026-09-01',
        ]);
        $colaborador->remuneraciones()->update(['salario' => 1800, 'vigencia_desde' => '2026-09-01']);
        foreach (['renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500] as $clave => $valor) {
            ParametroLaboralValor::create([
                'empresa_id' => $colaborador->empresa_id,
                'definicion_id' => ParametroLaboralDefinicion::where('clave', $clave)->value('id'),
                'regimen_laboral' => 'Locacion de Servicios',
                'vigencia_desde' => '2026-09-01',
                'valor' => $valor,
                'motivo' => 'Configuración de prueba',
            ]);
        }
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $colaborador->empresa_id, 'nombre' => 'Septiembre 2026',
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30',
            'fecha_corte_asistencia' => '2026-09-30', 'fecha_pago' => '2026-09-30', 'estado' => 'abierto',
        ]);
        app(CicloRemunerativoService::class)->registrarConcepto(
            $colaborador->empresa,
            $ciclo,
            $colaborador,
            [
                'concepto_id' => ConceptoRemuneracion::where('codigo', 'BONIFICACION')->value('id'),
                'monto' => 500,
                'motivo' => 'Bono por cumplimiento',
            ],
            \App\Models\User::firstOrFail()->id,
        );

        $resultado = app(CalcularReciboHonorarios::class)->calcular(
            $colaborador, '2026-09-01', '2026-09-30', '2026-09-30', $ciclo->id,
        );

        $this->assertSame(2300.0, $resultado['total_ingresos']);
        $this->assertSame(2300.0, $resultado['neto_a_pagar']);
        $this->assertSame(500.0, collect($resultado['ingresos'])->firstWhere('codigo', 'BONIFICACION')['monto']);
    }

    public function test_locador_con_honorario_vigente_despues_del_corte_y_dentro_del_mes_pagado_no_se_omite(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios',
            'fecha_ingreso' => '2026-10-28',
        ]);
        $colaborador->remuneraciones()->update([
            'salario' => 1400,
            'vigencia_desde' => '2026-10-28',
        ]);
        foreach (['renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500] as $clave => $valor) {
            ParametroLaboralValor::create([
                'empresa_id' => $colaborador->empresa_id,
                'definicion_id' => ParametroLaboralDefinicion::where('clave', $clave)->value('id'),
                'regimen_laboral' => 'Locacion de Servicios',
                'vigencia_desde' => '2026-10-01',
                'valor' => $valor,
                'motivo' => 'Configuración de prueba',
            ]);
        }

        $resultado = app(CalcularReciboHonorarios::class)->calcular(
            $colaborador, '2026-10-01', '2026-10-31', '2026-10-27',
        );

        $this->assertSame(1400.0, $resultado['sueldo_basico']);
        $this->assertGreaterThan(0, $resultado['ingresos'][0]['monto']);
        $this->assertSame('HONORARIO_BRUTO', $resultado['ingresos'][0]['codigo']);
    }

    public function test_un_locador_por_dias_presentes_cobra_solo_las_asistencias_reales(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios',
            'fecha_ingreso' => '2026-09-01',
        ]);
        $colaborador->remuneraciones()->update([
            'salario' => 1800,
            'modo_calculo_honorarios' => 'por_dias_presentes',
        ]);
        foreach (['renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500] as $clave => $valor) {
            ParametroLaboralValor::create([
                'empresa_id' => $colaborador->empresa_id,
                'definicion_id' => ParametroLaboralDefinicion::where('clave', $clave)->value('id'),
                'regimen_laboral' => 'Locacion de Servicios',
                'vigencia_desde' => '2026-09-01',
                'valor' => $valor,
                'motivo' => 'Configuración de prueba',
            ]);
        }

        foreach (['2026-09-12', '2026-09-18', '2026-09-19'] as $fecha) {
            AsistenciaResultadoDiario::create([
                'empresa_id' => $colaborador->empresa_id,
                'colaborador_id' => $colaborador->id,
                'fecha' => $fecha,
                'tipo_dia' => 'laborable_presencial',
                'estado' => 'presente',
                'procesado_at' => now(),
            ]);
        }
        AsistenciaResultadoDiario::create([
            'empresa_id' => $colaborador->empresa_id,
            'colaborador_id' => $colaborador->id,
            'fecha' => '2026-08-29',
            'tipo_dia' => 'laborable_presencial',
            'estado' => 'presente',
            'procesado_at' => now(),
        ]);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $fecha) {
            AsistenciaResultadoDiario::create([
                'empresa_id' => $colaborador->empresa_id,
                'colaborador_id' => $colaborador->id,
                'fecha' => $fecha,
                'tipo_dia' => 'laborable_presencial',
                'estado' => 'falta',
                'procesado_at' => now(),
            ]);
        }

        $resultado = app(CalcularReciboHonorarios::class)->calcular(
            $colaborador, '2026-09-01', '2026-09-30', '2026-09-26',
        );

        $this->assertSame(180.0, $resultado['total_ingresos']);
        $this->assertSame(0.0, $resultado['total_egresos']);
        $this->assertSame(180.0, $resultado['neto_a_pagar']);
        $this->assertCount(1, $resultado['ingresos']);
        $this->assertSame('HONORARIO_BRUTO', $resultado['ingresos'][0]['codigo']);
        $this->assertStringContainsString('12/09, 18/09, 19/09', $resultado['ingresos'][0]['formula_texto']);
    }
}
