<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class AsignarDescansoFlexibleSemanalTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_semana_partida_entre_dos_periodos_se_clasifica_por_segmento_sin_esperar(): void
    {
        $this->seed(DatabaseSeeder::class);

        $empresa = \App\Modules\Configuracion\Models\Empresa::firstOrFail();
        $empresa->update(['descanso_flexible_automatico' => true]);

        $lunes = Carbon::parse('2026-06-01')->startOfWeek(Carbon::MONDAY);
        $martes = $lunes->copy()->addDay();
        $miercoles = $lunes->copy()->addDays(2);
        $jueves = $lunes->copy()->addDays(3);
        $viernes = $lunes->copy()->addDays(4);
        $sabado = $lunes->copy()->addDays(5);
        $domingo = $lunes->copy()->addDays(6);

        $horarioRotativo = Horario::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Rotativo Test', 'tipo_turno' => 'rotativo',
            'vigencia_desde' => $lunes->copy()->subYear(), 'activo' => true,
        ]);
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => $lunes->copy()->subYear()->toDateString(),
            'es_trabajador_confianza' => false,
        ]);
        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id, 'horario_id' => $horarioRotativo->id,
            'dias_descanso_rotativo_por_semana' => 2, 'vigencia_desde' => $lunes->copy()->subYear(), 'vigencia_hasta' => null,
        ]);

        // Marca 2 fichadas (entrada/salida) en martes, miércoles, jueves y
        // domingo -- lunes, viernes y sábado quedan deliberadamente sin
        // ninguna marcación (los candidatos del ejemplo del plan).
        foreach ([$martes, $miercoles, $jueves, $domingo] as $fecha) {
            foreach (['08:00', '17:00'] as $hora) {
                AsistenciaMarcacion::create([
                    'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
                    'person_id' => $colaborador->numero_documento,
                    'marcado_at' => Carbon::parse("{$fecha->toDateString()} {$hora}"),
                    'origen' => 'manual_rrhh', 'dispositivo' => 'Test',
                ]);
            }
        }

        $usuario = User::factory()->create();
        $usuarioId = $usuario->id;
        $servicio = app(AsistenciaPeriodoService::class);

        // Periodo 1: lunes-miércoles (termina antes del domingo de la semana).
        $periodo1 = AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => $lunes->toDateString(), 'fecha_fin' => $miercoles->toDateString(), 'estado' => 'abierto',
        ]);
        // Cobertura corre en cola 'sync' -- el primer intento la dispara y
        // la completa en el mismo request, pero retorna el mensaje "en
        // proceso" sin volver a chequear; el segundo intento ya la ve lista.
        $servicio->prepararCierre($empresa, $periodo1, $usuarioId);
        $resultado1 = $servicio->prepararCierre($empresa, $periodo1, $usuarioId);
        $this->assertNull($resultado1, 'El primer segmento no debería generar pendientes (solo asigna 1 de 2 descansos, sin agotar la semana).');
        $servicio->cambiarEstado($empresa, $periodo1, 'cerrar', $usuarioId, 'Cierre de prueba');

        $lunesCalendario = ColaboradorCalendarioDia::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $lunes->toDateString())->firstOrFail();
        $this->assertSame('descanso', $lunesCalendario->tipo);
        $this->assertSame(ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO, $lunesCalendario->origen);

        // Periodo 2: jueves-domingo (incluye el domingo de la semana).
        $periodo2 = AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => $jueves->toDateString(), 'fecha_fin' => $domingo->toDateString(), 'estado' => 'abierto',
        ]);
        // 1ra llamada: dispara y corre la cobertura diaria (cola 'sync'),
        // retorna el mensaje "en proceso" sin llegar todavía a clasificar
        // nada (Fase 2 de prepararCierre() no corre hasta que la cobertura
        // ya esté confirmada completa).
        $servicio->prepararCierre($empresa, $periodo2, $usuarioId);

        // 2da llamada: cobertura ya completa -> clasifica el segmento
        // (viernes=descanso, sábado=falta) y recién ahí evalúa pendientes.
        // La falta del sábado (aunque la haya decidido esta función) sigue
        // el mismo flujo de revisión de CUALQUIER falta del sistema
        // (sincronizarIncidencia() ya genera TIPO_FALTA para cualquier
        // estado 'falta') -- por eso el cierre queda bloqueado hasta que
        // RR.HH. la revise, exactamente como pasaría con una falta de un
        // horario fijo cualquiera. La clasificación en sí queda persistida
        // aunque este intento de cierre se rechace (ver Fase 2 en
        // AsistenciaPeriodoService::prepararCierre()).
        try {
            $servicio->prepararCierre($empresa, $periodo2, $usuarioId);
            $this->fail('Se esperaba que la falta del sábado bloqueara el cierre hasta ser revisada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('faltas', $e->validator->errors()->first('pendientes'));
        }

        $viernesCalendario = ColaboradorCalendarioDia::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $viernes->toDateString())->firstOrFail();
        $this->assertSame('descanso', $viernesCalendario->tipo);

        $sabadoCalendario = ColaboradorCalendarioDia::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $sabado->toDateString())->firstOrFail();
        $this->assertSame('laborable_presencial', $sabadoCalendario->tipo);
        $this->assertSame(ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO, $sabadoCalendario->origen);

        $sabadoResultado = AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $sabado->toDateString())->firstOrFail();
        $this->assertSame('falta', $sabadoResultado->estado, 'Sábado no tuvo marcaciones y ya no quedaban cupos de descanso -- debe resolver como falta.');

        $domingoResultado = AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $domingo->toDateString())->firstOrFail();
        $incidenciasSemanales = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $domingoResultado->id)
            ->whereIn('tipo', [AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL, AsistenciaIncidencia::TIPO_DESCANSO_FLEXIBLE_INCOMPLETO])
            ->get();
        $this->assertCount(0, $incidenciasSemanales, 'Con los 2 descansos configurados cubiertos, no debería generarse ninguna incidencia semanal.');

        $faltaSabado = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $sabadoResultado->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_FALTA)
            ->firstOrFail();
        app(AsistenciaDecisionService::class)->resolverIncidencia($empresa, $faltaSabado, [
            'accion' => 'aprobar', 'motivo' => 'Falta confirmada en prueba automatizada.',
        ], $usuario);

        $resultado2 = $servicio->prepararCierre($empresa, $periodo2, $usuarioId);
        $this->assertNull($resultado2, 'Con la falta ya revisada y 2/2 descansos cubiertos, no debería quedar ningún pendiente.');
        $servicio->cambiarEstado($empresa, $periodo2, 'cerrar', $usuarioId, 'Cierre de prueba');

        $periodo1->refresh();
        $periodo2->refresh();
        $this->assertSame('cerrado', $periodo1->estado);
        $this->assertSame('cerrado', $periodo2->estado);
    }

    public function test_trabajar_todos_los_dias_aplicables_sin_ningun_descanso_genera_incidencia_severa(): void
    {
        $this->seed(DatabaseSeeder::class);

        $empresa = \App\Modules\Configuracion\Models\Empresa::firstOrFail();
        $empresa->update(['descanso_flexible_automatico' => true]);

        $lunes = Carbon::parse('2026-06-01')->startOfWeek(Carbon::MONDAY);

        $horarioRotativo = Horario::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Rotativo Test 2', 'tipo_turno' => 'rotativo',
            'vigencia_desde' => $lunes->copy()->subYear(), 'activo' => true,
        ]);
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => $lunes->copy()->subYear()->toDateString(),
            'es_trabajador_confianza' => false,
        ]);
        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id, 'horario_id' => $horarioRotativo->id,
            'dias_descanso_rotativo_por_semana' => 1, 'vigencia_desde' => $lunes->copy()->subYear(), 'vigencia_hasta' => null,
        ]);

        // Marca los 7 días de la semana -- ningún candidato disponible.
        for ($fecha = $lunes->copy(); $fecha->lte($lunes->copy()->addDays(6)); $fecha->addDay()) {
            foreach (['08:00', '17:00'] as $hora) {
                AsistenciaMarcacion::create([
                    'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
                    'person_id' => $colaborador->numero_documento,
                    'marcado_at' => Carbon::parse("{$fecha->toDateString()} {$hora}"),
                    'origen' => 'manual_rrhh', 'dispositivo' => 'Test',
                ]);
            }
        }

        $usuarioId = User::factory()->create()->id;
        $servicio = app(AsistenciaPeriodoService::class);

        $periodo = AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => $lunes->toDateString(), 'fecha_fin' => $lunes->copy()->addDays(6)->toDateString(), 'estado' => 'abierto',
        ]);
        $servicio->prepararCierre($empresa, $periodo, $usuarioId);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        try {
            $servicio->prepararCierre($empresa, $periodo, $usuarioId);
        } finally {
            $domingoResultado = AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)
                ->whereDate('fecha', $lunes->copy()->addDays(6)->toDateString())->first();
            $incidencia = AsistenciaIncidencia::query()
                ->where('resultado_diario_id', $domingoResultado->id)
                ->where('tipo', AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL)
                ->first();
            $this->assertNotNull($incidencia, 'Trabajar los 7 días sin ningún descanso debe generar la incidencia severa, y debe seguir persistida aunque el cierre se rechace.');
            $this->assertSame(AsistenciaIncidencia::ESTADO_PENDIENTE, $incidencia->estado);

            $periodo->refresh();
            $this->assertSame('abierto', $periodo->estado, 'El período no debe quedar cerrado mientras la incidencia siga pendiente.');
        }
    }
}
