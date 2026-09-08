<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class HorasExtraComplementariaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        ParametrosVigentesResolver::limpiarCache();
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2026-01-01',
            'regimen_laboral' => 'General',
            'contabilizar_horas_extra' => true,
        ]);
        $colaborador->remuneraciones()->update(['salario' => 3000, 'vigencia_desde' => '2026-01-01']);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 3000, 'dias_pagados' => 30,
            'total_ingresos' => 3015.63, 'total_egresos' => 391.98, 'total_aportaciones' => 271.41,
            'neto_a_pagar' => 2623.65, 'estado' => 'pagada', 'es_version_vigente' => true,
            'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        foreach ([
            ['SUELDO_BASICO', 'ingreso', 3000, 3000, 30],
            ['HE_25', 'ingreso', 15.63, 12.5, 1],
            ['ONP', 'egreso', 391.98, 3015.63, 1],
            ['ESSALUD', 'aportacion', 271.41, 3015.63, 1],
        ] as [$codigo, $tipo, $monto, $base, $cantidad]) {
            $concepto = ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail();
            $boleta->conceptos()->create([
                'concepto_id' => $concepto->id, 'tipo' => $tipo, 'monto' => $monto,
                'base_utilizada' => $base, 'cantidad' => $cantidad,
                'es_remunerativo_laboral' => $concepto->es_remunerativo_laboral,
                'afecta_renta_5ta' => $concepto->afecta_renta_5ta,
            ]);
        }
        $complementaria = PlanillaComplementaria::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id,
            'nombre' => 'Regularizaciones agosto', 'motivo' => 'Lote único',
            'estado' => 'calculada', 'creado_por' => $usuario->id,
        ]);
        $service = app(PlanillaComplementariaService::class);
        $service->agregarColaboradores($empresa, $complementaria, [$boleta->id]);

        return [$empresa, $ciclo, $boleta, $complementaria, $usuario, $service];
    }

    private function horaAprobada(Empresa $empresa, Boleta $boleta, string $fecha, int $minutos): AsistenciaHoraExtra
    {
        $resultado = AsistenciaResultadoDiario::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $boleta->colaborador_id,
            'fecha' => $fecha, 'tipo_dia' => 'laborable', 'estado' => 'completo',
            'minutos_extra_observados' => $minutos, 'minutos_extra_25' => $minutos, 'procesado_at' => now(),
        ]);

        return AsistenciaHoraExtra::create([
            'empresa_id' => $empresa->id, 'resultado_diario_id' => $resultado->id,
            'colaborador_id' => $boleta->colaborador_id, 'fecha' => $fecha,
            'minutos_observados' => $minutos, 'minutos_solicitados' => $minutos,
            'minutos_aprobados' => $minutos, 'tasa' => '25', 'estado' => 'aprobado',
            'motivo' => 'Marcación validada',
        ]);
    }

    public function test_solo_ofrece_minutos_del_huellero_no_cubiertos_por_la_boleta(): void
    {
        [$empresa, $ciclo, $boleta, , , $service] = $this->escenario();
        $primera = $this->horaAprobada($empresa, $boleta, '2026-08-05', 60);
        $segunda = $this->horaAprobada($empresa, $boleta, '2026-08-06', 120);

        $pendientes = collect($service->horasExtraPendientes($empresa, $ciclo)['horas']);

        $this->assertFalse($pendientes->contains('id', $primera->id));
        $this->assertSame(120, $pendientes->firstWhere('id', $segunda->id)['minutos_pendientes']);
    }

    public function test_agrega_parcialmente_hora_detectada_y_eliminarla_libera_los_minutos(): void
    {
        [$empresa, $ciclo, $boleta, $complementaria, $usuario, $service] = $this->escenario();
        $this->horaAprobada($empresa, $boleta, '2026-08-05', 60);
        $hora = $this->horaAprobada($empresa, $boleta, '2026-08-06', 120);

        $item = $service->agregarHorasExtra($empresa, $complementaria, [
            ['hora_extra_id' => $hora->id, 'minutos' => 60],
        ], [], $usuario->id);
        $detalle = $item->detalles->first();
        $regularizada = $detalle->calculo_snapshot['horas_extra_regularizadas'][0];
        $this->assertSame(60, $regularizada['minutos']);
        $this->assertEquals(15.63, $regularizada['monto']);
        $this->assertSame(60, collect($service->horasExtraPendientes($empresa, $ciclo)['horas'])->firstWhere('id', $hora->id)['minutos_pendientes']);

        $service->eliminarConcepto($empresa, $detalle, $regularizada['linea_id']);
        $this->assertSame(120, collect($service->horasExtraPendientes($empresa, $ciclo)['horas'])->firstWhere('id', $hora->id)['minutos_pendientes']);
    }

    public function test_permite_ingreso_manual_con_fecha_tasa_y_sustento(): void
    {
        [$empresa, , $boleta, $complementaria, $usuario, $service] = $this->escenario();

        $item = $service->agregarHorasExtra($empresa, $complementaria, [], [[
            'boleta_id' => $boleta->id, 'fecha' => '2026-08-10', 'minutos' => 120,
            'tasa' => '35', 'motivo' => 'Trabajo autorizado sin marcación biométrica',
        ]], $usuario->id);
        $hora = $item->detalles->first()->calculo_snapshot['horas_extra_regularizadas'][0];

        $this->assertSame('manual', $hora['origen']);
        $this->assertSame('35', $hora['tasa']);
        $this->assertSame(120, $hora['minutos']);
        $this->assertEquals(33.75, $hora['monto']);
        $this->assertSame('Trabajo autorizado sin marcación biométrica', $hora['motivo']);
    }

    public function test_autorizacion_actual_permite_regularizar_horas_historicas(): void
    {
        [$empresa, , $boleta, $complementaria, $usuario, $service] = $this->escenario();
        $colaborador = $boleta->colaborador;
        $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => 'General', 'tipo_contrato' => 'indefinido',
            'contabilizar_horas_extra' => false, 'vigencia_desde' => '2026-08-01',
        ]);
        $colaborador->condicionesLaborales()->create([
            'regimen_laboral' => 'General', 'tipo_contrato' => 'indefinido',
            'contabilizar_horas_extra' => true, 'vigencia_desde' => '2026-09-01',
        ]);
        $colaborador->update(['contabilizar_horas_extra' => true]);

        $item = $service->agregarHorasExtra($empresa, $complementaria, [], [[
            'boleta_id' => $boleta->id, 'fecha' => '2026-08-19', 'minutos' => 60,
            'tasa' => '25', 'motivo' => 'Autorización posterior de RR.HH.',
        ]], $usuario->id);

        $this->assertSame(60, $item->detalles->first()->calculo_snapshot['horas_extra_regularizadas'][0]['minutos']);
    }

    public function test_crear_horas_extra_reutiliza_el_borrador_donde_ya_esta_el_colaborador(): void
    {
        [$empresa, $ciclo, $boleta, $complementaria, $usuario, $service] = $this->escenario();

        $item = $service->crearConHorasExtra($empresa, $ciclo, [], [[
            'boleta_id' => $boleta->id, 'fecha' => '2026-08-20', 'minutos' => 60,
            'tasa' => '25', 'motivo' => 'Hora omitida',
        ]], 'Pago de horas pendientes', $usuario->id);

        $this->assertSame($complementaria->id, $item->id);
        $this->assertSame(1, PlanillaComplementaria::where('ciclo_id', $ciclo->id)->count());
        $this->assertSame(60, $item->detalles->first()->calculo_snapshot['horas_extra_regularizadas'][0]['minutos']);
    }

    public function test_una_complementaria_aprobada_no_bloquea_crear_otra_para_el_mismo_colaborador(): void
    {
        [$empresa, $ciclo, $boleta, $complementaria, $usuario, $service] = $this->escenario();
        $service->agregarHorasExtra($empresa, $complementaria, [], [[
            'boleta_id' => $boleta->id, 'fecha' => '2026-08-19', 'minutos' => 60,
            'tasa' => '25', 'motivo' => 'Primera regularización',
        ]], $usuario->id);
        $service->aprobar($empresa, $complementaria, $usuario->id);

        $nuevo = $service->crearConHorasExtra($empresa, $ciclo, [], [[
            'boleta_id' => $boleta->id, 'fecha' => '2026-08-20', 'minutos' => 60,
            'tasa' => '25', 'motivo' => 'Hora adicional omitida',
        ]], 'Segundo pago de horas pendientes', $usuario->id);

        $this->assertNotSame($complementaria->id, $nuevo->id);
        $this->assertSame('aprobada', $complementaria->fresh()->estado);
        $this->assertSame('calculada', $nuevo->estado);
        $this->assertSame(2, PlanillaComplementaria::where('ciclo_id', $ciclo->id)->count());
    }

    public function test_no_ofrece_ni_permite_pagar_una_hora_al_100_ya_cubierta_por_feriado_historico(): void
    {
        [$empresa, $ciclo, $boleta, $complementaria, $usuario, $service] = $this->escenario();
        // Un colaborador solo puede tener una complementaria pendiente por ciclo
        // (misma regla que agregarColaboradores/crearFeriadoBloqueado). Se libera
        // el borrador de escenario() para poder generar primero la regularización
        // del feriado y, luego de pagarla, verificar que ya no se puede duplicar.
        $service->eliminar($empresa, $complementaria);

        $resultado = AsistenciaResultadoDiario::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $boleta->colaborador_id,
            'fecha' => '2026-08-06', 'tipo_dia' => 'feriado', 'estado' => 'completo',
            'minutos_extra_observados' => 480, 'minutos_extra_100' => 480, 'procesado_at' => now(),
        ]);
        $hora = AsistenciaHoraExtra::create([
            'empresa_id' => $empresa->id, 'resultado_diario_id' => $resultado->id,
            'colaborador_id' => $boleta->colaborador_id, 'fecha' => '2026-08-06',
            'minutos_observados' => 480, 'minutos_solicitados' => 480,
            'minutos_aprobados' => 480, 'tasa' => '100', 'estado' => 'aprobado',
            'motivo' => 'Feriado trabajado',
        ]);

        $feriado = $service->crearRegularizacionFeriadoHistorico($empresa, $ciclo, [$boleta->id], '2026-08-06', 'Sin descanso sustitutorio ni pago previo', $usuario->id);
        $service->aprobar($empresa, $feriado, $usuario->id);
        $service->marcarPagada($empresa, $feriado, $usuario->id, 'REF-TEST');

        $pendientes = collect($service->horasExtraPendientes($empresa, $ciclo)['horas']);
        $this->assertFalse($pendientes->contains('id', $hora->id));

        $nuevoBorrador = PlanillaComplementaria::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id,
            'nombre' => 'Otro lote', 'motivo' => 'Horas extra', 'estado' => 'calculada', 'creado_por' => $usuario->id,
        ]);
        $service->agregarColaboradores($empresa, $nuevoBorrador, [$boleta->id]);

        $this->expectException(ValidationException::class);
        $service->agregarHorasExtra($empresa, $nuevoBorrador, [['hora_extra_id' => $hora->id, 'minutos' => 60]], [], $usuario->id);
    }
}
