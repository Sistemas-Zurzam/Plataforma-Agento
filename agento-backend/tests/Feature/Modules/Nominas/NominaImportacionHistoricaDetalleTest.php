<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NominaImportacionHistoricaDetalleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migracion_crea_la_tabla_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('nomina_importacion_historica_detalles'));
        $this->assertTrue(Schema::hasColumns('nomina_importacion_historica_detalles', [
            'importacion_id', 'empresa_id', 'hoja_nombre', 'fila_numero', 'datos_originales',
            'tipo_documento_normalizado', 'numero_documento_normalizado', 'colaborador_id',
            'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'tipo_antecedente',
            'anio', 'mes', 'fecha_periodo_inicio', 'fecha_periodo_fin', 'fecha_pago_deposito', 'fecha_corte',
            'importe', 'dias_cantidad', 'estado_excel', 'estado_validacion', 'errores', 'advertencias',
            'fingerprint_negocio',
        ]));
    }

    public function test_guarda_correctamente_sus_casts_y_pertenece_a_su_lote(): void
    {
        $importacion = NominaImportacionHistorica::factory()->create();
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id,
            'empresa_id' => $importacion->empresa_id,
            'datos_originales' => ['nombre' => 'fila cruda'],
            'importe' => 1234.56,
        ]);

        $this->assertTrue($detalle->importacion->is($importacion));
        $this->assertTrue($detalle->empresa->is($importacion->empresa));
        $this->assertSame(['nombre' => 'fila cruda'], $detalle->fresh()->datos_originales);
        $this->assertSame('1234.56', $detalle->fresh()->importe);
    }

    public function test_la_misma_hoja_y_fila_no_se_duplica_dentro_de_un_lote(): void
    {
        $importacion = NominaImportacionHistorica::factory()->create();

        NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $importacion->empresa_id,
            'hoja_nombre' => 'Gratificaciones', 'fila_numero' => 5,
        ]);

        $this->expectException(QueryException::class);
        NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $importacion->empresa_id,
            'hoja_nombre' => 'Gratificaciones', 'fila_numero' => 5,
        ]);
    }

    public function test_la_misma_hoja_y_fila_si_puede_repetirse_en_lotes_distintos(): void
    {
        $empresa = Empresa::factory()->create();
        $loteUno = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);
        $loteDos = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);

        NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $loteUno->id, 'empresa_id' => $empresa->id,
            'hoja_nombre' => 'Gratificaciones', 'fila_numero' => 5,
        ]);
        $filaEnOtroLote = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $loteDos->id, 'empresa_id' => $empresa->id,
            'hoja_nombre' => 'Gratificaciones', 'fila_numero' => 5,
        ]);

        $this->assertDatabaseHas('nomina_importacion_historica_detalles', ['id' => $filaEnOtroLote->id]);
    }

    public function test_el_mismo_antecedente_de_negocio_no_se_aplica_dos_veces_aunque_el_excel_se_reimporte(): void
    {
        $empresa = Empresa::factory()->create();
        $primerLote = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);
        $segundoLote = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);
        $fingerprint = hash('sha256', 'empresa|colaborador|gratificacion_julio|2026-S1');

        NominaImportacionHistoricaDetalle::factory()->aplicado()->create([
            'importacion_id' => $primerLote->id, 'empresa_id' => $empresa->id,
            'fingerprint_negocio' => $fingerprint,
        ]);

        $this->expectException(QueryException::class);
        NominaImportacionHistoricaDetalle::factory()->aplicado()->create([
            'importacion_id' => $segundoLote->id, 'empresa_id' => $empresa->id,
            'fingerprint_negocio' => $fingerprint,
        ]);
    }

    /**
     * Una fila observada/errónea (nunca aplicada) con el mismo fingerprint
     * no debe bloquear su corrección — la idempotencia real es "no aplicar
     * dos veces", no "no observar dos veces".
     */
    public function test_una_fila_observada_con_el_mismo_fingerprint_no_esta_bloqueada_mientras_no_se_aplique(): void
    {
        $empresa = Empresa::factory()->create();
        $lote = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);
        $fingerprint = hash('sha256', 'empresa|colaborador|cts_mayo|2026-S1');

        NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $lote->id, 'empresa_id' => $empresa->id,
            'fingerprint_negocio' => $fingerprint, 'estado_validacion' => 'observado', 'fila_numero' => 1,
        ]);
        $corregida = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $lote->id, 'empresa_id' => $empresa->id,
            'fingerprint_negocio' => $fingerprint, 'estado_validacion' => 'valido', 'fila_numero' => 2,
        ]);

        $this->assertDatabaseHas('nomina_importacion_historica_detalles', ['id' => $corregida->id]);
    }
}
