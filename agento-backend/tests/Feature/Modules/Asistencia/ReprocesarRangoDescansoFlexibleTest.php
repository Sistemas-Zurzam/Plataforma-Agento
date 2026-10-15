<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Application\ReprocesarAsistenciaRango;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * El botón "Reprocesar" (Gestión de Asistencias → Colaboradores) opera sobre
 * un rango de fechas libre, sin necesitar ningún AsistenciaPeriodo creado —
 * a diferencia de "Cerrar período"/"Verificar asistencia". Este test cubre
 * que el descanso semanal flexible automático también se aplique ahí,
 * exactamente sin crear ningún período.
 */
class ReprocesarRangoDescansoFlexibleTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_reprocesar_un_rango_sin_periodo_tambien_aplica_el_descanso_flexible(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $empresa->update(['descanso_flexible_automatico' => true]);

        $lunes = Carbon::parse('2026-06-01')->startOfWeek(Carbon::MONDAY);
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
            'dias_descanso_rotativo_por_semana' => 1, 'vigencia_desde' => $lunes->copy()->subYear(), 'vigencia_hasta' => null,
        ]);

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

        $this->assertSame(0, AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)->count(), 'Este escenario no debe depender de ningún período creado.');

        $usuarioId = User::factory()->create()->id;
        $resultado = app(ReprocesarAsistenciaRango::class)->ejecutar(
            $empresa, $usuarioId, $lunes->toDateString(), $lunes->copy()->addDays(6)->toDateString(),
            [$colaborador->id], 'Reprocesamiento de prueba',
        );

        $this->assertGreaterThan(0, $resultado['procesados']);

        $lunesCalendario = ColaboradorCalendarioDia::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $lunes->toDateString())->firstOrFail();
        $this->assertSame('descanso', $lunesCalendario->tipo);
        $this->assertSame(ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO, $lunesCalendario->origen);

        $lunesResultado = AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $lunes->toDateString())->firstOrFail();
        $this->assertSame('descanso', $lunesResultado->estado);

        $this->assertSame(0, AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)->count(), 'Tampoco debe crear ningún período como efecto secundario.');
    }
}
