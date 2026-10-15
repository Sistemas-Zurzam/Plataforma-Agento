<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Models\HorarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class CambioHorarioRetroactivoTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_cambio_retroactivo_requiere_confirmacion_y_reprocesa_sin_borrar_marcaciones(): void
    {
        $this->seed(DatabaseSeeder::class);
        $fecha = Carbon::parse('2026-09-28');
        $colaborador = $this->crearColaborador(atributos: [
            'fecha_ingreso' => $fecha->copy()->subMonth()->toDateString(),
        ]);
        $empresa = $colaborador->empresa;
        $horarioAnterior = $colaborador->horario;
        $horarioNuevo = Horario::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Turno corregido',
            'tipo_turno' => 'normal', 'vigencia_desde' => $fecha->copy()->subMonth(),
            'activo' => true,
        ]);
        foreach ([[$horarioAnterior, '08:00', '17:00'], [$horarioNuevo, '09:00', '18:00']] as [$horario, $entrada, $salida]) {
            HorarioDia::create([
                'horario_id' => $horario->id, 'dia_semana' => $fecha->dayOfWeekIso - 1,
                'estado' => 'laborable', 'hora_entrada' => $entrada, 'hora_salida' => $salida,
            ]);
        }
        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'horario_id' => $horarioAnterior->id, 'vigencia_desde' => $fecha->copy()->subMonth(),
        ]);
        foreach (['08:30', '18:00'] as $hora) {
            AsistenciaMarcacion::create([
                'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
                'person_id' => $colaborador->numero_documento,
                'marcado_at' => Carbon::parse($fecha->toDateString().' '.$hora),
                'origen' => 'manual_rrhh', 'dispositivo' => 'Test',
            ]);
        }
        $original = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador, $fecha);
        $this->assertGreaterThan(0, $original->minutos_tardanza);
        $datos = [
            'horario_id' => $horarioNuevo->id, 'modalidad_trabajo' => 'presencial',
            'tolerancia_particular_minutos' => null, 'vigencia_desde' => $fecha->toDateString(),
            'vigencia_hasta' => null,
        ];
        $servicio = app(ColaboradorService::class);
        $usuarioId = User::factory()->create()->id;

        $previo = $servicio->actualizarHorario($empresa, $colaborador, $datos, $usuarioId);
        $this->assertTrue($previo['requiere_confirmacion']);
        $this->assertSame(1, $previo['impacto']['resultados_procesados']);
        $this->assertSame($horarioAnterior->id, $colaborador->fresh()->horario_id);

        $servicio->actualizarHorario($empresa, $colaborador, $datos, $usuarioId, true, true);
        $corregido = AsistenciaResultadoDiario::findOrFail($original->id);
        $this->assertSame(0, $corregido->minutos_tardanza);
        $this->assertSame(2, AsistenciaMarcacion::where('colaborador_id', $colaborador->id)->count());
        $this->assertSame($horarioNuevo->id, $colaborador->fresh()->horario_id);
    }
}
