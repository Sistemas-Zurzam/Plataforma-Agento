<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * verificarCobertura() existe para que RR.HH. pueda revisar la clasificación
 * real de los días (cobertura diaria + descanso flexible) DURANTE el mes,
 * sin necesidad de intentar "Cerrar período" (que ya implica listo para
 * pagar) solo para ver el resultado. A diferencia de prepararCierre(), nunca
 * debe lanzar por pendientes ni cambiar el estado del período.
 */
class VerificarCoberturaPeriodoTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function crearColaboradorRotativo(Empresa $empresa, Carbon $lunes, int $diasDescanso): \App\Modules\Personas\Models\Colaborador
    {
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
            'dias_descanso_rotativo_por_semana' => $diasDescanso, 'vigencia_desde' => $lunes->copy()->subYear(), 'vigencia_hasta' => null,
        ]);

        return $colaborador;
    }

    public function test_verificar_clasifica_los_dias_y_no_cierra_el_periodo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $empresa->update(['descanso_flexible_automatico' => true]);

        $lunes = Carbon::parse('2026-06-01')->startOfWeek(Carbon::MONDAY);
        $colaborador = $this->crearColaboradorRotativo($empresa, $lunes, 1);

        // Marca 6 de 7 días -- el lunes queda como único candidato a descanso.
        for ($fecha = $lunes->copy()->addDay(); $fecha->lte($lunes->copy()->addDays(6)); $fecha->addDay()) {
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

        // La cobertura corre en cola 'sync' -- el primer intento la dispara
        // y la completa en el mismo request, pero retorna "en proceso" sin
        // clasificar todavía; el segundo intento ya la ve lista.
        $servicio->verificarCobertura($empresa, $periodo, $usuarioId);
        $resultado = $servicio->verificarCobertura($empresa, $periodo, $usuarioId);

        $this->assertSame('completa', $resultado['cobertura_estado']);
        $this->assertNull($resultado['pendientes'], 'Con el único candidato cubriendo la única cuota de descanso, no debería quedar ningún pendiente.');

        $lunesCalendario = ColaboradorCalendarioDia::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $lunes->toDateString())->firstOrFail();
        $this->assertSame('descanso', $lunesCalendario->tipo);
        $this->assertSame(ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO, $lunesCalendario->origen);

        $periodo->refresh();
        $this->assertSame('abierto', $periodo->estado, 'verificarCobertura() nunca debe cerrar el período, solo reconciliar.');
    }

    public function test_verificar_devuelve_pendientes_en_vez_de_lanzar(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $empresa->update(['descanso_flexible_automatico' => true]);

        $lunes = Carbon::parse('2026-06-01')->startOfWeek(Carbon::MONDAY);
        $colaborador = $this->crearColaboradorRotativo($empresa, $lunes, 1);

        // Marca los 7 días de la semana -- ningún candidato disponible para
        // el único descanso configurado, así que la semana genera la
        // incidencia severa de "sin descanso semanal".
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

        $servicio->verificarCobertura($empresa, $periodo, $usuarioId);
        // A diferencia de prepararCierre() (que lanzaría ValidationException
        // acá), verificarCobertura() debe devolver los pendientes sin lanzar.
        $resultado = $servicio->verificarCobertura($empresa, $periodo, $usuarioId);

        $this->assertSame('completa', $resultado['cobertura_estado']);
        $this->assertNotNull($resultado['pendientes']);
        $this->assertArrayHasKey(AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL, $resultado['pendientes']['incidencias']);

        $periodo->refresh();
        $this->assertSame('abierto', $periodo->estado);
    }
}
