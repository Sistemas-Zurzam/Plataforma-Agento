<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Application\ReprocesarAsistenciaRango;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Botón "Reprocesar" con dos modos: el reproceso que conserva cambios
 * manuales (parcial / reproceso de rango) mantiene el estado forzado por
 * RR.HH. y sus marcaciones manuales; "Reprocesar todo" los descarta.
 */
class ReprocesarModosTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_restablecer_ajustes_manuales_es_solo_para_administrador(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $rol = Role::create(['clave' => 'asistencia_prueba', 'nombre' => 'Asistencia prueba']);
        $rol->permissions()->sync(Permission::where('clave', 'asistencia.procesar')->pluck('id'));
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->updateExistingPivot($empresa->id, ['role_id' => $rol->id]);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
        Auth::forgetGuards();
        $rango = ['fecha_desde' => '2026-06-01', 'fecha_hasta' => '2026-06-07'];

        $this->withHeaders($headers)->postJson('/api/asistencia/reprocesar', [...$rango, 'modo' => 'total', 'motivo' => 'Prueba'])
            ->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/asistencia/reprocesar', $rango)->assertOk();
    }

    public function test_reproceso_conserva_cambios_manuales_y_reprocesar_todo_los_descarta(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $fecha = Carbon::parse('2026-06-03');
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => $fecha->copy()->subYear()->toDateString(),
            'es_trabajador_confianza' => false,
        ]);
        foreach (['08:00', '17:00'] as $hora) {
            AsistenciaMarcacion::create([
                'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
                'person_id' => $colaborador->numero_documento,
                'marcado_at' => Carbon::parse("{$fecha->toDateString()} {$hora}"),
                'origen' => 'Attendance Device', 'dispositivo' => 'Test',
            ]);
        }

        $resultado = app(ProcesarAsistenciaDiaria::class)->procesar($colaborador, $fecha);
        $estadoMotor = $resultado->estado;
        $estadoForzado = $estadoMotor === 'falta' ? 'presente' : 'falta';

        $usuario = User::factory()->create();
        app(AsistenciaDecisionService::class)->editarDia($empresa, $resultado, [
            'entrada' => '07:30', 'estado' => $estadoForzado, 'motivo' => 'Corrección de prueba',
        ], $usuario);

        $reprocesador = app(ReprocesarAsistenciaRango::class);
        $rango = [$fecha->toDateString(), $fecha->toDateString()];

        $reprocesador->ejecutar($empresa, $usuario->id, ...$rango, colaboradorIds: [$colaborador->id], motivo: 'Parcial');

        $conservado = AsistenciaResultadoDiario::query()->where('colaborador_id', $colaborador->id)->whereDate('fecha', $fecha)->firstOrFail();
        $this->assertSame($estadoForzado, $conservado->estado);
        $this->assertSame($estadoForzado, $conservado->estado_forzado);
        $this->assertSame(1, $this->marcacionesManualesVigentes($colaborador->id));

        $resultadoTotal = $reprocesador->ejecutar(
            $empresa, $usuario->id, ...$rango, colaboradorIds: [$colaborador->id], motivo: 'Todo', descartarCambiosManuales: true,
        );

        $descartado = $conservado->fresh();
        $this->assertNull($descartado->estado_forzado);
        $this->assertSame($estadoMotor, $descartado->estado);
        $this->assertSame(0, $this->marcacionesManualesVigentes($colaborador->id));
        $this->assertSame(1, $resultadoTotal['cambios_descartados']['marcaciones_anuladas']);
        $this->assertSame(1, $resultadoTotal['cambios_descartados']['estados_forzados']);
        $this->assertArrayNotHasKey('fechas_adicionales', $resultadoTotal['cambios_descartados']);
    }

    private function marcacionesManualesVigentes(int $colaboradorId): int
    {
        return AsistenciaMarcacion::query()
            ->where('colaborador_id', $colaboradorId)
            ->where('origen', 'manual_rrhh')
            ->whereNull('anulada_at')
            ->count();
    }
}
