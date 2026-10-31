<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Asistencia\Models\HorarioDia;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class TurnoQueCruzaMedianocheTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_salida_despues_de_medianoche_cierra_un_turno_no_marcado_como_nocturno(): void
    {
        $fecha = Carbon::parse('2026-10-05');
        $colaborador = $this->colaboradorConTurno($fecha, '14:00', '01:00');
        $this->marcar($colaborador, '2026-10-05 14:06:29');
        $this->marcar($colaborador, '2026-10-06 00:16:14');

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador, $fecha);

        $this->assertSame('presente', $resultado->estado);
        $this->assertSame('2026-10-06 00:16:14', $resultado->salida_at->format('Y-m-d H:i:s'));
    }

    public function test_salida_mal_configurada_no_toma_marcaciones_del_dia_siguiente(): void
    {
        // 08:00-05:00 (21 h) es casi seguro un error de carga de 17:00: no
        // debe robar la entrada del día siguiente como salida de hoy.
        $fecha = Carbon::parse('2026-10-05');
        $colaborador = $this->colaboradorConTurno($fecha, '08:00', '05:00');
        $this->marcar($colaborador, '2026-10-05 08:02:00');
        $this->marcar($colaborador, '2026-10-06 07:58:00');

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador, $fecha);

        $this->assertSame('marcacion_incompleta', $resultado->estado);
    }

    private function colaboradorConTurno(Carbon $fecha, string $entrada, string $salida): Colaborador
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'fecha_ingreso' => $fecha->copy()->subMonth()->toDateString(),
        ]);
        $horario = Horario::create([
            'empresa_id' => $colaborador->empresa_id, 'nombre' => "{$entrada}-{$salida}",
            'tipo_turno' => 'normal', 'vigencia_desde' => $fecha->copy()->subMonth(),
            'activo' => true,
        ]);
        HorarioDia::create([
            'horario_id' => $horario->id, 'dia_semana' => $fecha->dayOfWeekIso - 1,
            'estado' => 'laborable', 'hora_entrada' => $entrada, 'hora_salida' => $salida,
            'jornada_nocturna' => false,
        ]);
        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'horario_id' => $horario->id, 'vigencia_desde' => $fecha->copy()->subMonth(),
        ]);

        return $colaborador;
    }

    private function marcar(Colaborador $colaborador, string $momento): void
    {
        AsistenciaMarcacion::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'person_id' => $colaborador->numero_documento,
            'marcado_at' => Carbon::parse($momento),
            'origen' => 'attendance_device', 'dispositivo' => 'Test',
        ]);
    }
}
