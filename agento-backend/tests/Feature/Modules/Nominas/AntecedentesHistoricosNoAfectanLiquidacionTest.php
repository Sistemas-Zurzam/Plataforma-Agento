<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use App\Modules\Nominas\Models\SaldoLaboralPendiente;
use App\Modules\Nominas\Models\SaldoVacacionalHistorico;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Incremento 1 (ver DIAGNOSTICO_LIQUIDACIONES_HISTORICAS.md): las tablas de
 * antecedentes históricos son puramente aditivas y todavía NO son leídas
 * por ningún servicio de cálculo. Este test prueba esa afirmación en vivo:
 * la existencia de filas en las 5 tablas nuevas para el MISMO colaborador
 * no cambia en absoluto el resultado de `LiquidacionCeseService::previsualizar()`
 * — ni LiquidacionCeseService, ni BeneficioSocialService, ni BoletaService,
 * ni CicloRemunerativoService se modificaron en este incremento.
 */
class AntecedentesHistoricosNoAfectanLiquidacionTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_el_calculo_de_liquidacion_es_identico_con_y_sin_antecedentes_historicos_cargados(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);

        $seleccion = ['incluir_remuneracion' => true, 'incluir_cts' => true, 'incluir_gratificacion' => true, 'incluir_vacaciones' => true];

        $resultadoSinAntecedentes = app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa, $colaborador, now()->toDateString(), $seleccion,
        );

        BeneficioSocialHistorico::factory()->pagado()->create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
        ]);
        SaldoVacacionalHistorico::factory()->aprobado()->create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'dias_pendientes' => 20,
        ]);
        SaldoLaboralPendiente::factory()->aprobado()->create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importe_original' => 500,
        ]);

        $resultadoConAntecedentes = app(LiquidacionCeseService::class)->previsualizar(
            $colaborador->empresa, $colaborador, now()->toDateString(), $seleccion,
        );

        $this->assertSame($resultadoSinAntecedentes['total_ingresos'], $resultadoConAntecedentes['total_ingresos']);
        $this->assertSame($resultadoSinAntecedentes['total_egresos'], $resultadoConAntecedentes['total_egresos']);
        $this->assertSame($resultadoSinAntecedentes['neto_pagar'], $resultadoConAntecedentes['neto_pagar']);
        $this->assertSame($resultadoSinAntecedentes['conceptos'], $resultadoConAntecedentes['conceptos']);
    }
}
