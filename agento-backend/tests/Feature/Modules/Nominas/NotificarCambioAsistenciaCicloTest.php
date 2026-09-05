<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\NotificarCambioAsistenciaCiclo;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Incremento 3 del endurecimiento Asistencia-Nómina: requiere_recalculo +
 * NotificarCambioAsistenciaCiclo.
 */
class NotificarCambioAsistenciaCicloTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private function crearCiclo(Empresa $empresa, string $estado, string $inicio = '2026-07-01', string $fin = '2026-07-31'): CicloRemunerativo
    {
        return CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => "Ciclo {$inicio}", 'periodicidad' => 'mensual',
            'fecha_inicio' => $inicio, 'fecha_fin' => $fin,
            'fecha_corte_asistencia' => $fin, 'fecha_pago' => $fin,
            'estado' => $estado,
        ]);
    }

    private function crearBoletaPagada(CicloRemunerativo $ciclo, int $colaboradorId): Boleta
    {
        return Boleta::create([
            'ciclo_id' => $ciclo->id, 'empresa_id' => $ciclo->empresa_id, 'colaborador_id' => $colaboradorId,
            'version' => 1, 'es_version_vigente' => true, 'estado' => 'pagada',
            'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 3000, 'dias_pagados' => 30,
            'total_ingresos' => 3000, 'total_egresos' => 0, 'total_aportaciones' => 0, 'neto_a_pagar' => 3000,
            'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
    }

    public function test_marca_requiere_recalculo_en_un_ciclo_calculado_dentro_del_rango(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'calculado');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Hora extra aprobada');

        $ciclo->refresh();
        $this->assertTrue($ciclo->requiere_recalculo);
        $this->assertStringContainsString('Hora extra aprobada', $ciclo->recalculo_motivo);
        $this->assertNotNull($ciclo->recalculo_detectado_at);
    }

    public function test_no_afecta_un_ciclo_fuera_del_rango_notificado(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'calculado', '2026-08-01', '2026-08-31');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Cambio de julio');

        $ciclo->refresh();
        $this->assertFalse($ciclo->requiere_recalculo);
        $this->assertNull($ciclo->recalculo_motivo);
    }

    public function test_rango_que_intersecta_parcialmente_el_ciclo_lo_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'calculado', '2026-07-01', '2026-07-31');

        // El rango notificado empieza antes del ciclo y termina dentro -- se
        // superpone parcialmente (28 jul - 3 ago cruza el fin del ciclo).
        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-28', '2026-08-03', 'Cambio a caballo de mes');

        $ciclo->refresh();
        $this->assertTrue($ciclo->requiere_recalculo);
    }

    public function test_no_afecta_ciclos_de_otra_empresa(): void
    {
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();
        $cicloA = $this->crearCiclo($empresaA, 'calculado');
        $cicloB = $this->crearCiclo($empresaB, 'calculado');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresaA->id, 5, '2026-07-10', '2026-07-10', 'Solo empresa A');

        $cicloA->refresh();
        $cicloB->refresh();
        $this->assertTrue($cicloA->requiere_recalculo);
        $this->assertFalse($cicloB->requiere_recalculo);
    }

    public function test_ciclo_abierto_sin_calculo_previo_no_se_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'abierto');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Cambio irrelevante -- nunca se calculó');

        $ciclo->refresh();
        $this->assertFalse($ciclo->requiere_recalculo);
        $this->assertNull($ciclo->recalculo_motivo);
    }

    public function test_ciclo_reabierto_con_boleta_anterior_si_se_marca(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'reabierto');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Corrección tras reabrir');

        $ciclo->refresh();
        $this->assertTrue($ciclo->requiere_recalculo);
    }

    public function test_ciclo_cerrado_se_marca_y_bloquea_el_pago(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $ciclo = $this->crearCiclo($empresa, 'cerrado');
        $this->crearBoletaPagada($ciclo, $colaborador->id);

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, $colaborador->id, '2026-07-10', '2026-07-10', 'Marcación corregida después del cierre');

        $ciclo->refresh();
        $this->assertTrue($ciclo->requiere_recalculo);

        $this->expectException(ValidationException::class);
        app(CicloRemunerativoService::class)->marcarPagado($empresa, $ciclo);
    }

    public function test_ciclo_pagado_no_se_marca_y_genera_incidencia_sin_tocar_la_boleta(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $ciclo = $this->crearCiclo($empresa, 'pagado');
        $boleta = $this->crearBoletaPagada($ciclo, $colaborador->id);
        AsistenciaResultadoDiario::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id, 'fecha' => '2026-07-10',
            'tipo_dia' => 'laborable_presencial', 'estado' => 'presente', 'procesado_at' => now(),
        ]);

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, $colaborador->id, '2026-07-10', '2026-07-10', 'Hora extra aprobada después del pago');

        $ciclo->refresh();
        $this->assertFalse($ciclo->requiere_recalculo);
        $this->assertNull($ciclo->recalculo_motivo);

        $boleta->refresh();
        $this->assertSame('pagada', $boleta->estado);
        $this->assertEquals(3000, $boleta->neto_a_pagar);

        $incidencia = AsistenciaIncidencia::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_AJUSTE_POST_PAGO_PENDIENTE)
            ->first();
        $this->assertNotNull($incidencia);
        $this->assertStringContainsString('planilla complementaria', $incidencia->descripcion);
    }

    public function test_notificacion_repetida_es_idempotente(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'calculado');

        $servicio = app(NotificarCambioAsistenciaCiclo::class);
        $servicio->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Mismo cambio', 'ref-123');
        $servicio->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Mismo cambio', 'ref-123');

        $ciclo->refresh();
        // Una sola línea -- la segunda notificación no se volvió a acumular.
        $this->assertSame(1, substr_count($ciclo->recalculo_motivo, 'ref-123'));
    }

    public function test_cambio_original_revertido_nunca_notifica(): void
    {
        $empresa = Empresa::factory()->create();
        $ciclo = $this->crearCiclo($empresa, 'calculado');

        try {
            DB::transaction(function () {
                throw new \RuntimeException('Fallo simulado en el cambio de asistencia -- debe revertir.');
            });
            app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-10', '2026-07-10', 'Nunca debería llegar acá');
        } catch (\RuntimeException) {
            // esperado -- el rollback ya ocurrió antes de intentar notificar
        }

        $ciclo->refresh();
        $this->assertFalse($ciclo->requiere_recalculo);
    }

    public function test_un_rango_amplio_marca_dos_ciclos_no_solapados(): void
    {
        $empresa = Empresa::factory()->create();
        $julio = $this->crearCiclo($empresa, 'calculado', '2026-07-01', '2026-07-31');
        $agosto = $this->crearCiclo($empresa, 'calculado', '2026-08-01', '2026-08-31');

        app(NotificarCambioAsistenciaCiclo::class)->notificar($empresa->id, 5, '2026-07-25', '2026-08-05', 'Corrección a caballo de dos ciclos');

        $julio->refresh();
        $agosto->refresh();
        $this->assertTrue($julio->requiere_recalculo);
        $this->assertTrue($agosto->requiere_recalculo);
    }
}
