<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\SaldoVacacionalHistorico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class SaldoVacacionalHistoricoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_la_migracion_crea_la_tabla_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('saldos_vacacionales_historicos'));
        $this->assertTrue(Schema::hasColumns('saldos_vacacionales_historicos', [
            'empresa_id', 'colaborador_id', 'importacion_detalle_id',
            'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'fecha_corte',
            'dias_devengados', 'dias_gozados', 'dias_pagados', 'dias_pendientes',
            'estado', 'origen', 'observaciones',
            'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
        ]));
    }

    public function test_guarda_correctamente_sus_casts_y_relaciones(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $saldo = SaldoVacacionalHistorico::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'dias_pendientes' => 12.5,
        ]);

        $this->assertTrue($saldo->colaborador->is($colaborador));
        $this->assertSame('12.5000', $saldo->fresh()->dias_pendientes);
    }

    public function test_solo_puede_existir_un_saldo_aprobado_vigente_por_vinculo_y_fecha_de_corte(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $datos = [
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'fecha_corte' => '2026-07-31',
        ];

        SaldoVacacionalHistorico::factory()->aprobado()->create($datos);

        $this->expectException(QueryException::class);
        SaldoVacacionalHistorico::factory()->aprobado()->create($datos);
    }

    public function test_un_borrador_descartado_no_bloquea_registrar_el_saldo_aprobado_definitivo(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $datos = [
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'fecha_corte' => '2026-07-31',
        ];

        SaldoVacacionalHistorico::factory()->create($datos); // queda en 'borrador'
        $aprobado = SaldoVacacionalHistorico::factory()->aprobado()->create($datos);

        $this->assertDatabaseHas('saldos_vacacionales_historicos', ['id' => $aprobado->id, 'estado' => 'aprobado']);
    }

    public function test_una_fecha_de_corte_anterior_al_ingreso_es_rechazada_por_el_modelo(): void
    {
        $invalido = SaldoVacacionalHistorico::factory()->make([
            'fecha_ingreso_vinculo' => '2026-08-01', 'fecha_corte' => '2026-07-31',
        ]);
        $this->assertFalse($invalido->fechaCorteEsValida());

        $valido = SaldoVacacionalHistorico::factory()->make([
            'fecha_ingreso_vinculo' => '2025-01-02', 'fecha_corte' => '2026-07-31',
        ]);
        $this->assertTrue($valido->fechaCorteEsValida());
    }

    public function test_un_saldo_vacacional_negativo_es_rechazado_por_el_modelo(): void
    {
        $negativo = SaldoVacacionalHistorico::factory()->make(['dias_pendientes' => -5]);
        $this->assertFalse($negativo->diasPendientesEsValido());

        $valido = SaldoVacacionalHistorico::factory()->make(['dias_pendientes' => 5]);
        $this->assertTrue($valido->diasPendientesEsValido());
    }

    public function test_la_eliminacion_fisica_de_un_colaborador_con_saldo_historico_queda_restringida(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        SaldoVacacionalHistorico::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
        ]);

        $this->expectException(QueryException::class);
        $colaborador->forceDelete();
    }
}
