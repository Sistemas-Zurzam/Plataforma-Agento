<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\SaldoLaboralPendiente;
use App\Modules\Nominas\Models\SaldoVacacionalHistorico;
use App\Modules\Nominas\Services\AplicarImportacionHistoricaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * `AplicarImportacionHistoricaService` es el único punto de escritura
 * autorizado para promover filas de staging a antecedentes definitivos —
 * corrección post-revisión del Incremento 1 (el propietario detectó que las
 * validaciones del modelo no protegían nada si ningún servicio las llamaba).
 */
class AplicarImportacionHistoricaServiceTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // User::factory() (usuario que aplica el lote) depende de que exista
        // el rol por defecto — mismo requisito que LiquidacionCeseServiceTest.
        $this->seed(DatabaseSeeder::class);
    }

    private function crearLoteAprobado(Empresa $empresa, array $atributos = []): NominaImportacionHistorica
    {
        return NominaImportacionHistorica::factory()->create([
            'empresa_id' => $empresa->id, 'estado' => 'aprobado', ...$atributos,
        ]);
    }

    private function crearDetalle(NominaImportacionHistorica $lote, array $atributos = []): NominaImportacionHistoricaDetalle
    {
        return NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $lote->id, 'empresa_id' => $lote->empresa_id,
            'estado_validacion' => 'valido', 'clasificacion' => 'aplicable', ...$atributos,
        ]);
    }

    public function test_aplica_un_lote_con_filas_validas_de_los_tres_tipos_soportados(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);

        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            // mes=7: la gratificación solo se calcula en julio o diciembre, y
            // el aplicador clasifica directamente por ese mes (nunca un mes
            // dentro de un periodo más amplio).
            'tipo_antecedente' => 'gratificacion_pagada', 'anio' => 2026, 'mes' => 7,
            'fecha_periodo_inicio' => '2026-01-01', 'fecha_periodo_fin' => '2026-06-30',
            'fecha_corte' => '2026-07-31', 'importe' => 1500, 'hoja_nombre' => 'Gratificaciones', 'fila_numero' => 1,
        ]);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 12,
            'hoja_nombre' => 'Vacaciones', 'fila_numero' => 1,
        ]);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'prestamo_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 500,
            'nombre_concepto_original' => 'Préstamo personal', 'hoja_nombre' => 'Prestamos', 'fila_numero' => 1,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $resultado = app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);

        $this->assertSame('aplicado', $resultado->estado);
        $this->assertSame(3, $resultado->filas_aplicadas);
        $this->assertSame($usuarioId, $resultado->aplicado_por);
        $this->assertSame(3, NominaImportacionHistoricaDetalle::where('importacion_id', $lote->id)->where('estado_validacion', 'aplicado')->count());

        $this->assertDatabaseHas('beneficios_sociales_historicos', [
            'colaborador_id' => $colaborador->id, 'tipo' => 'gratificacion_julio', 'estado' => 'pagado', 'importe_bruto' => '1500.00',
        ]);
        $this->assertDatabaseHas('saldos_vacacionales_historicos', [
            'colaborador_id' => $colaborador->id, 'dias_pendientes' => '12.0000', 'estado' => 'aprobado',
        ]);
        $this->assertDatabaseHas('saldos_laborales_pendientes', [
            'colaborador_id' => $colaborador->id, 'tipo' => 'prestamo', 'importe_original' => '500.00', 'estado' => 'aprobado',
        ]);
    }

    public function test_rechaza_aplicar_un_lote_que_no_esta_aprobado(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'borrador']);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 5,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $this->expectException(ValidationException::class);
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
    }

    /**
     * Un lote con filas — pero ninguna con clasificacion=aplicable Y
     * estado_validacion=valido a la vez (observada, con error, ignorada) —
     * debe rechazarse igual que un lote vacío: el lote permanece "aprobado"
     * y no se crea ningún antecedente definitivo.
     */
    public function test_rechaza_un_lote_sin_filas_validas(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 8,
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
        ]);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'prestamo_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 100,
            'clasificacion' => 'error', 'estado_validacion' => 'error',
        ]);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'adelanto_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 100,
            'clasificacion' => 'no_aplicable', 'estado_validacion' => 'ignorado',
        ]);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        try {
            app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
            $this->fail('Se esperaba que la aplicación del lote lanzara una excepción.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame('aprobado', $lote->fresh()->estado);
        $this->assertSame(0, SaldoLaboralPendiente::count());
        $this->assertSame(0, SaldoVacacionalHistorico::count());
        $this->assertSame(0, BeneficioSocialHistorico::count());
    }

    /**
     * Un lote con una fila realmente aplicable y varias observadas: solo la
     * válida se aplica; las observadas quedan intactas, sin antecedente.
     */
    public function test_aplica_solo_la_fila_valida_ignorando_las_observadas(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);

        $filaValida = $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'prestamo_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 500,
            'hoja_nombre' => 'Prestamos', 'fila_numero' => 1,
        ]);
        $filaObservadaUno = $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'adelanto_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 200,
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
        ]);
        $filaObservadaDos = $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'descuento_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 300,
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $resultado = app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);

        $this->assertSame(1, $resultado->filas_aplicadas);
        $this->assertSame('aplicado', $filaValida->fresh()->estado_validacion);
        $this->assertSame('observado', $filaObservadaUno->fresh()->estado_validacion);
        $this->assertSame('observado', $filaObservadaDos->fresh()->estado_validacion);
        $this->assertSame(1, SaldoLaboralPendiente::count());
        $this->assertDatabaseHas('saldos_laborales_pendientes', [
            'colaborador_id' => $colaborador->id, 'tipo' => 'prestamo', 'importe_original' => '500.00',
        ]);
    }

    /**
     * Todo o nada: una fila inconsistente (días negativos) debe abortar
     * TODO el lote, incluida la fila válida que la acompaña — nunca aplicar
     * una parte y dejar la otra pendiente.
     */
    public function test_una_fila_invalida_revierte_el_lote_completo(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);

        $filaValida = $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'prestamo_pendiente', 'fecha_corte' => '2026-07-31', 'importe' => 500,
            'hoja_nombre' => 'Prestamos', 'fila_numero' => 1,
        ]);
        $filaInvalida = $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => -5,
            'hoja_nombre' => 'Vacaciones', 'fila_numero' => 1,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        try {
            app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
            $this->fail('Se esperaba que la aplicación del lote lanzara una excepción.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame('aprobado', $lote->fresh()->estado);
        $this->assertSame(0, SaldoLaboralPendiente::count());
        $this->assertSame(0, SaldoVacacionalHistorico::count());
        $this->assertSame('valido', $filaValida->fresh()->estado_validacion);
        $this->assertSame('valido', $filaInvalida->fresh()->estado_validacion);
    }

    public function test_permite_antecedente_de_un_colaborador_cesado_cuando_la_fecha_de_corte_es_anterior_al_cese(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2020-01-02', 'activo' => false, 'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
        ]);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-05-31', 'dias_cantidad' => 8,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $resultado = app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);

        $this->assertSame('aplicado', $resultado->estado);
        $this->assertDatabaseHas('saldos_vacacionales_historicos', ['colaborador_id' => $colaborador->id, 'dias_pendientes' => '8.0000']);
    }

    public function test_rechaza_antecedente_con_fecha_de_corte_posterior_al_cese(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2020-01-02', 'activo' => false, 'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
        ]);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 8,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $this->expectException(ValidationException::class);
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
    }

    public function test_rechaza_antecedente_de_colaborador_con_liquidacion_ya_pagada_sin_autorizacion_explicita(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2020-01-02', 'activo' => false, 'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
        ]);
        LiquidacionCese::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
            'remuneracion_snapshot' => 3000, 'regimen_laboral_snapshot' => 'General',
            'total_ingresos' => 3000, 'total_egresos' => 0, 'neto_pagar' => 3000,
            'estado' => 'pagada', 'version' => 1, 'es_version_vigente' => true,
        ]);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-05-31', 'dias_cantidad' => 8,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $this->expectException(ValidationException::class);
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
    }

    public function test_permite_antecedente_de_colaborador_con_liquidacion_pagada_cuando_el_lote_lo_autoriza(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2020-01-02', 'activo' => false, 'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
        ]);
        LiquidacionCese::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_cese' => '2026-06-30', 'motivo_cese' => 'Renuncia',
            'remuneracion_snapshot' => 3000, 'regimen_laboral_snapshot' => 'General',
            'total_ingresos' => 3000, 'total_egresos' => 0, 'neto_pagar' => 3000,
            'estado' => 'pagada', 'version' => 1, 'es_version_vigente' => true,
        ]);
        $lote = $this->crearLoteAprobado($empresa, ['autoriza_cesados_con_liquidacion_pagada' => true]);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-05-31', 'dias_cantidad' => 8,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $resultado = app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);

        $this->assertSame('aplicado', $resultado->estado);
    }

    public function test_rechaza_si_la_fecha_de_ingreso_del_vinculo_no_coincide_con_el_colaborador(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso->copy()->subYear(), // vínculo distinto al registrado
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 8,
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $this->expectException(ValidationException::class);
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
    }

    /**
     * Endurecimiento del Incremento 2: una fila `observado` con
     * `estado_validacion=valido` (caso hipotético, nunca producido por el
     * clasificador real) no debe aplicarse — el filtro exige
     * `clasificacion=aplicable` Y `estado_validacion=valido` a la vez.
     */
    public function test_no_aplica_una_fila_observada_aunque_su_estado_validacion_sea_valido(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'saldo_vacacional', 'fecha_corte' => '2026-07-31', 'dias_cantidad' => 8,
            'clasificacion' => 'observado', 'estado_validacion' => 'valido',
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $this->expectException(ValidationException::class);
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);
    }

    public function test_una_cts_confirmada_manualmente_se_aplica_con_su_referencia_y_fecha_confirmadas(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $lote = $this->crearLoteAprobado($empresa);
        $this->crearDetalle($lote, [
            'colaborador_id' => $colaborador->id, 'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo_antecedente' => 'cts_depositada', 'fecha_corte' => '2026-05-31', 'importe' => 500,
            'anio' => 2026, 'mes' => 5,
            'referencia_pago_confirmada' => 'Num Operacion - 001', 'fecha_pago_confirmada' => '2026-05-15',
        ]);

        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        app(AplicarImportacionHistoricaService::class)->aplicar($empresa, $lote, $usuarioId);

        $beneficio = BeneficioSocialHistorico::where('colaborador_id', $colaborador->id)->firstOrFail();
        $this->assertSame('cts_mayo', $beneficio->tipo);
        $this->assertSame('Num Operacion - 001', $beneficio->referencia_externa);
        $this->assertSame('2026-05-15', $beneficio->fecha_pago_deposito->toDateString());
    }
}
