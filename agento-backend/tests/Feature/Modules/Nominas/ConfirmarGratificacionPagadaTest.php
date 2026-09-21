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

class ConfirmarGratificacionPagadaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function crearLoteConDetalleGratificacion(array $atributos = []): array
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'PLANILLA', 'nombre_concepto_original' => 'GRATIFICACION ORDINARIA', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado', 'mes' => 7, 'anio' => 2026,
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importe' => 1500, ...$atributos,
        ]);

        return [$empresa, $colaborador, $importacion, $detalle];
    }

    public function test_confirma_correctamente_y_deja_auditoria(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $resultado = app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), 'Plla 10', 'Confirmado con Contabilidad', $usuarioId,
        );

        $this->assertSame('aplicable', $resultado->clasificacion);
        $this->assertSame('valido', $resultado->estado_validacion);
        $this->assertSame('gratificacion_pagada', $resultado->tipo_antecedente);
        $this->assertDatabaseHas('nomina_importacion_historica_detalle_correcciones', [
            'detalle_id' => $detalle->id, 'campo' => 'confirmacion_gratificacion_pagada',
        ]);
    }

    public function test_rechaza_si_el_concepto_no_es_gratificacion_ordinaria(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion(['nombre_concepto_original' => 'CTS']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_el_mes_no_es_julio_ni_diciembre(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion(['mes' => 3]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_pago_es_posterior_al_cese(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, ['activo' => false, 'fecha_cese' => '2026-06-30']);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'PLANILLA', 'nombre_concepto_original' => 'GRATIFICACION ORDINARIA', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado', 'mes' => 7, 'anio' => 2026,
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importe' => 1500,
        ]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-31'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_falta_motivo(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), null, '', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_pago_es_anterior_al_ingreso(): void
    {
        $empresa = Empresa::factory()->create();
        // Ingreso posterior al periodo de la fila: el pago de julio 2026 no
        // puede ser anterior al ingreso del colaborador (agosto 2026).
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-08-01']);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_calculo_original' => 'PLANILLA', 'nombre_concepto_original' => 'GRATIFICACION ORDINARIA', 'estado_excel' => 'Cancelado',
            'clasificacion' => 'observado', 'estado_validacion' => 'observado', 'mes' => 7, 'anio' => 2026,
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importe' => 1500,
        ]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_pago_es_posterior_al_corte_del_lote(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $importacion->update(['fecha_corte' => '2026-06-30']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_pago_es_futura(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $importacion->update(['fecha_corte' => Carbon::today()->addMonths(3)]);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::today()->addMonth(), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_si_la_fecha_de_pago_es_incompatible_con_el_periodo_declarado(): void
    {
        // mes=12 implica pago esperado en diciembre de 2026, no en agosto.
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion(['mes' => 12]);
        $importacion->update(['fecha_corte' => '2026-12-31']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-08-15'), null, 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_aprobado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $importacion->update(['estado' => 'aprobado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), 'Plla 10', 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_aplicado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $importacion->update(['estado' => 'aplicado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), 'Plla 10', 'Motivo', 1,
        );
    }

    public function test_rechaza_confirmar_cuando_el_lote_ya_esta_anulado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalleGratificacion();
        $importacion->update(['estado' => 'anulado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->confirmarGratificacionPagada(
            $empresa, $importacion, $detalle, Carbon::parse('2026-07-15'), 'Plla 10', 'Motivo', 1,
        );
    }
}
