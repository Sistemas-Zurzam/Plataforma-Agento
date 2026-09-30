<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class PagoHonorariosPorDiasPresentesTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

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
            $colaborador, '2026-09-01', '2026-09-30', '2026-09-30',
        );

        $this->assertSame(180.0, $resultado['total_ingresos']);
        $this->assertSame(0.0, $resultado['total_egresos']);
        $this->assertSame(180.0, $resultado['neto_a_pagar']);
        $this->assertCount(1, $resultado['ingresos']);
        $this->assertSame('HONORARIO_BRUTO', $resultado['ingresos'][0]['codigo']);
    }
}
