<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Services\ImportarAntecedentesHistoricosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ConfirmarCtsDepositadaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class); // User::factory() depende del rol por defecto
    }

    private function crearLoteConDetalleCts(array $atributos = []): array
    {
        $empresa = Empresa::factory()->create();
        // fecha_ingreso muy anterior a las fechas de depósito usadas en las
        // pruebas (mayo 2026): evita falsos rechazos por "fecha anterior al
        // ingreso" en el camino feliz.
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2020-01-02']);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'CTS', 'nombre_concepto_original' => 'CTS', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importe' => 500, ...$atributos,
        ]);

        return [$empresa, $colaborador, $importacion, $detalle];
    }

    public function test_confirma_correctamente_y_deja_auditoria(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $resultado = app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Num Operacion - 001', Carbon::parse('2026-05-15'), 'Confirmado con estado de cuenta bancario', $usuarioId,
        );

        $this->assertSame('aplicable', $resultado->clasificacion);
        $this->assertSame('valido', $resultado->estado_validacion);
        $this->assertSame('cts_depositada', $resultado->tipo_antecedente);
        $this->assertSame('Num Operacion - 001', $resultado->referencia_pago_confirmada);
        $this->assertDatabaseHas('nomina_importacion_historica_detalle_correcciones', [
            'detalle_id' => $detalle->id, 'campo' => 'confirmacion_cts_depositada',
        ]);
    }

    public function test_rechaza_si_el_concepto_no_es_cts(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts(['nombre_concepto_original' => 'GRATIFICACION ORDINARIA']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_el_estado_original_no_era_cancelado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts(['estado_excel' => 'Pendiente Pago']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_falta_referencia(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, '', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_el_colaborador_no_esta_resuelto(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts(['colaborador_id' => null]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_falta_motivo(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), '', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_deposito_es_anterior_al_ingreso(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2019-06-01'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_deposito_es_posterior_al_corte_del_lote(): void
    {
        // fecha_corte del factory es 2026-07-31.
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-08-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_deposito_es_posterior_al_cese(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2020-01-02', 'activo' => false, 'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
        ]);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'CTS', 'nombre_concepto_original' => 'CTS', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importe' => 500,
        ]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-07-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_deposito_es_futura(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();
        // Empuja el corte del lote lejos en el futuro para que no interfiera:
        // esta prueba aísla específicamente la regla de "fecha futura".
        $importacion->update(['fecha_corte' => Carbon::today()->addMonths(2)]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::today()->addMonth(), 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_deposito_es_incompatible_con_el_periodo_declarado(): void
    {
        // mes=5 implica depósito esperado en mayo de 2026, no en junio.
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts(['anio' => 2026, 'mes' => 5]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-06-10'), 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_aprobado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();
        $importacion->update(['estado' => 'aprobado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_aplicado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();
        $importacion->update(['estado' => 'aplicado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_anulado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleCts();
        $importacion->update(['estado' => 'anulado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarCtsDepositada(
            $empresa, $importacion, $detalle, 'Ref', Carbon::parse('2026-05-15'), 'Motivo', 1,
        );
    }
}
