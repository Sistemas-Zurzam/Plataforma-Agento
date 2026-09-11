<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\SaldoLaboralPendiente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class SaldoLaboralPendienteTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_la_migracion_crea_la_tabla_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('saldos_laborales_pendientes'));
        $this->assertTrue(Schema::hasColumns('saldos_laborales_pendientes', [
            'empresa_id', 'colaborador_id', 'importacion_detalle_id',
            'fecha_ingreso_vinculo', 'fecha_fin_vinculo',
            'tipo', 'descripcion', 'importe_original', 'importe_aplicado', 'saldo_pendiente',
            'fecha_corte', 'estado', 'origen', 'referencia_externa', 'observaciones',
            'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
        ]));
    }

    public function test_guarda_correctamente_sus_casts_y_relaciones(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $saldo = SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importe_original' => 1000, 'importe_aplicado' => 250,
        ]);

        $this->assertTrue($saldo->colaborador->is($colaborador));
        $this->assertSame('1000.00', $saldo->fresh()->importe_original);
        $this->assertSame('250.00', $saldo->fresh()->importe_aplicado);
    }

    /**
     * `saldo_pendiente` es una columna GENERADA por la base de datos
     * (importe_original - importe_aplicado) — nunca se asigna a mano; este
     * test confirma que el valor persistido es el que calcula el motor, no
     * uno que la aplicación haya escrito.
     */
    public function test_el_saldo_pendiente_lo_calcula_la_base_de_datos(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $saldo = SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importe_original' => 1000, 'importe_aplicado' => 300,
        ]);

        $this->assertSame('700.00', $saldo->fresh()->saldo_pendiente);
    }

    public function test_un_importe_aplicado_no_supera_el_importe_original(): void
    {
        $coherente = SaldoLaboralPendiente::factory()->make(['importe_original' => 1000, 'importe_aplicado' => 1000]);
        $this->assertTrue($coherente->saldoEsCoherente());

        $incoherente = SaldoLaboralPendiente::factory()->make(['importe_original' => 1000, 'importe_aplicado' => 1000.01]);
        $this->assertFalse($incoherente->saldoEsCoherente());
    }

    /**
     * Cada fila de origen del Excel solo puede promoverse a UN saldo
     * laboral pendiente — evita que un reintento de importación duplique el
     * mismo préstamo/adelanto/descuento.
     */
    public function test_una_fila_de_origen_no_puede_generar_dos_saldos_pendientes(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id]);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_antecedente' => 'prestamo_pendiente',
        ]);

        SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importacion_detalle_id' => $detalle->id,
        ]);

        $this->expectException(QueryException::class);
        SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importacion_detalle_id' => $detalle->id,
        ]);
    }

    public function test_varios_saldos_sin_fila_de_origen_conviven_sin_problema(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $uno = SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importacion_detalle_id' => null,
        ]);
        $dos = SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso, 'importacion_detalle_id' => null,
        ]);

        $this->assertDatabaseHas('saldos_laborales_pendientes', ['id' => $uno->id]);
        $this->assertDatabaseHas('saldos_laborales_pendientes', ['id' => $dos->id]);
    }

    public function test_la_eliminacion_fisica_de_un_colaborador_con_saldo_pendiente_queda_restringida(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        SaldoLaboralPendiente::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
        ]);

        $this->expectException(QueryException::class);
        $colaborador->forceDelete();
    }
}
