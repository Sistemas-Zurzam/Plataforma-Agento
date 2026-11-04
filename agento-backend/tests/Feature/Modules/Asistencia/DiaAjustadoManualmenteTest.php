<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Application\ReprocesarAsistenciaRango;
use App\Modules\Asistencia\Models\AsistenciaImportacion;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\NotificarCambioAsistenciaCiclo;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Un día editado a mano por RR.HH. queda protegido: importaciones y
 * recálculos posteriores no lo tocan (solo avisan si llegan marcaciones
 * nuevas) y solo "Restablecer" lo vuelve a calcular.
 */
class DiaAjustadoManualmenteTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private function marcar(Empresa $empresa, Colaborador $colaborador, string $momento, ?Carbon $registradaEn = null): AsistenciaMarcacion
    {
        $marcacion = AsistenciaMarcacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'person_id' => $colaborador->numero_documento, 'marcado_at' => Carbon::parse($momento),
            'origen' => 'Attendance Device', 'dispositivo' => 'Reloj',
        ]);
        if ($registradaEn) {
            $marcacion->forceFill(['created_at' => $registradaEn])->save();
        }

        return $marcacion;
    }

    private function resultado(Colaborador $colaborador, string $fecha): AsistenciaResultadoDiario
    {
        return AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $fecha)->firstOrFail();
    }

    public function test_dia_ajustado_hasta_el_27_no_cambia_al_recalcular_e_importar_dias_posteriores(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $procesador = app(ProcesarAsistenciaDiaria::class);

        $this->marcar($empresa, $colaborador, '2026-09-27 09:30');
        $this->marcar($empresa, $colaborador, '2026-09-27 18:00');
        $procesador->procesar($colaborador, Carbon::parse('2026-09-27'));

        $editado = app(AsistenciaDecisionService::class)->editarDia($empresa, $this->resultado($colaborador, '2026-09-27'), [
            'entrada' => '08:00', 'salida' => '17:00', 'estado' => 'presente', 'motivo' => 'Corrección de marcación',
        ], $usuario);
        $this->assertTrue($editado->estaAjustadoManualmente());
        // La corrección manual prevalece sobre las marcas del reloj.
        $this->assertSame('08:00', $editado->entrada_at->format('H:i'));
        $this->assertSame('17:00', $editado->salida_at->format('H:i'));

        // Llegan marcaciones nuevas del 27 (Excel tardío) y del 28-29.
        $this->marcar($empresa, $colaborador, '2026-09-27 07:00', now()->addMinute());
        $this->marcar($empresa, $colaborador, '2026-09-28 08:00');
        $this->marcar($empresa, $colaborador, '2026-09-28 17:00');

        $resultado = app(ReprocesarAsistenciaRango::class)->ejecutar(
            $empresa, $usuario->id, '2026-09-27', '2026-09-29', [$colaborador->id], 'Recalcular rango',
        );

        $dia27 = $this->resultado($colaborador, '2026-09-27');
        $this->assertSame('08:00', $dia27->entrada_at->format('H:i'), 'El día ajustado no debe recalcularse.');
        $this->assertSame('presente', $dia27->estado);
        $this->assertSame(1, $resultado['conservados_por_ajuste_manual']);
        $this->assertSame(2, $resultado['procesados']);
        $this->assertNotNull($this->resultado($colaborador, '2026-09-28')->entrada_at);

        $aviso = AsistenciaIncidencia::query()->where('resultado_diario_id', $dia27->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_MARCACIONES_EN_DIA_AJUSTADO)->first();
        $this->assertNotNull($aviso);
        $this->assertSame(AsistenciaIncidencia::ESTADO_PENDIENTE, $aviso->estado);

        // Restablecer (solo Administrador en la API) sí lo recalcula desde el reloj.
        app(ReprocesarAsistenciaRango::class)->ejecutar(
            $empresa, $usuario->id, '2026-09-27', '2026-09-27', [$colaborador->id], 'Restablecer', descartarCambiosManuales: true,
        );
        $restablecido = $this->resultado($colaborador, '2026-09-27');
        $this->assertFalse($restablecido->estaAjustadoManualmente());
        $this->assertSame('07:00', $restablecido->entrada_at->format('H:i'));
    }

    public function test_recalcular_un_dia_sin_cambios_no_vuelve_a_avisar_a_nomina(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $this->marcar($empresa, $colaborador, '2026-09-10 08:00');
        $this->marcar($empresa, $colaborador, '2026-09-10 17:00');

        $this->mock(NotificarCambioAsistenciaCiclo::class, fn ($mock) => $mock->shouldReceive('notificar')->once());
        $procesador = app(ProcesarAsistenciaDiaria::class);

        $procesador->procesar($colaborador, Carbon::parse('2026-09-10'));
        $procesador->procesar($colaborador, Carbon::parse('2026-09-10'));
    }

    public function test_recalcular_ultima_importacion_solo_incluye_a_los_colaboradores_del_archivo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $admin = User::where('username', 'test.user')->firstOrFail();
        $admin->update(['empresa_id' => $empresa->id]);
        $enArchivo = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);
        $fueraDelArchivo = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);

        $importacion = AsistenciaImportacion::create([
            'empresa_id' => $empresa->id, 'importado_por' => $admin->id, 'archivo_nombre' => 'semana.xlsx',
            'archivo_hash' => str_repeat('a', 64), 'estado' => 'completada',
            'fecha_minima' => '2026-09-14', 'fecha_maxima' => '2026-09-15',
            'filas_totales' => 2, 'filas_nuevas' => 2, 'filas_duplicadas' => 0, 'filas_observadas' => 0,
        ]);
        $importacion->marcaciones()->attach([
            $this->marcar($empresa, $enArchivo, '2026-09-14 08:00')->id => ['fue_nueva' => true],
            $this->marcar($empresa, $enArchivo, '2026-09-14 17:00')->id => ['fue_nueva' => true],
        ]);

        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($admin->fresh())])
            ->postJson('/api/asistencia/reprocesar', ['modo' => 'parcial'])
            ->assertOk();

        $this->assertTrue(AsistenciaResultadoDiario::query()->where('colaborador_id', $enArchivo->id)->exists());
        $this->assertFalse(
            AsistenciaResultadoDiario::query()->where('colaborador_id', $fueraDelArchivo->id)->exists(),
            'A un colaborador que no venía en el archivo no se le deben generar faltas.',
        );
    }
}
