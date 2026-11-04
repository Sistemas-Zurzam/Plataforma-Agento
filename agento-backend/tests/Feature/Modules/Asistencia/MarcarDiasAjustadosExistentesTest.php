<?php

namespace Tests\Feature\Modules\Asistencia;

use App\Models\User;
use App\Modules\Asistencia\Application\MarcarDiasAjustadosExistentes;
use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Llenado inicial de la marca de ajuste manual al desplegar: solo se
 * protegen los días que todavía conservan el ajuste de RR.HH.
 */
class MarcarDiasAjustadosExistentesTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    private Colaborador $colaborador;

    private User $usuario;

    private function dia(string $fecha, string $estado, ?string $estadoForzado = null): AsistenciaResultadoDiario
    {
        return AsistenciaResultadoDiario::create([
            'empresa_id' => $this->empresa->id, 'colaborador_id' => $this->colaborador->id, 'fecha' => $fecha,
            'tipo_dia' => 'laborable_presencial', 'estado' => $estado, 'estado_forzado' => $estadoForzado, 'procesado_at' => now(),
        ]);
    }

    private function edicion(AsistenciaResultadoDiario $dia, string $antes, string $despues, Carbon $en): void
    {
        AsistenciaAuditoria::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->usuario->id, 'colaborador_id' => $this->colaborador->id,
            'entidad_tipo' => AsistenciaResultadoDiario::class, 'entidad_id' => $dia->id, 'accion' => 'resultado_editado',
            'motivo' => 'Corrección RR.HH.', 'antes' => ['estado' => $antes], 'despues' => ['estado' => $despues],
        ])->forceFill(['created_at' => $en])->save();
    }

    public function test_solo_marca_los_dias_que_conservan_evidencia_del_ajuste(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::firstOrFail();
        $this->usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        $this->colaborador = $this->crearColaborador($this->empresa, ['fecha_ingreso' => '2026-01-01']);
        $hace = now()->subDays(5);

        // 1) Marcación manual vigente.
        $conMarca = $this->dia('2026-09-01', 'presente');
        AsistenciaMarcacion::create([
            'empresa_id' => $this->empresa->id, 'colaborador_id' => $this->colaborador->id, 'person_id' => $this->colaborador->numero_documento,
            'marcado_at' => '2026-09-01 08:00', 'origen' => 'manual_rrhh', 'dispositivo' => 'Edición RR.HH.',
        ]);
        // 2) Estado forzado ya registrado.
        $conForzado = $this->dia('2026-09-02', 'presente', 'presente');
        // 3) Estado forzado antes de existir estado_forzado y todavía vigente.
        $forzadoAntiguo = $this->dia('2026-09-03', 'presente');
        $this->edicion($forzadoAntiguo, 'falta', 'presente', $hace);
        // 4) Edición que ya se perdió en un reproceso posterior.
        $perdido = $this->dia('2026-09-04', 'falta');
        $this->edicion($perdido, 'falta', 'presente', $hace);
        // 5) Edición vigente por coincidencia, pero el rango se restableció después.
        $restablecido = $this->dia('2026-09-05', 'presente');
        $this->edicion($restablecido, 'falta', 'presente', $hace);
        AsistenciaAuditoria::create([
            'empresa_id' => $this->empresa->id, 'usuario_id' => $this->usuario->id, 'entidad_tipo' => 'rango_asistencia',
            'accion' => 'asistencia_cambios_manuales_descartados', 'motivo' => 'Restablecer',
            'despues' => ['fecha_desde' => '2026-09-05', 'fecha_hasta' => '2026-09-05', 'colaborador_ids' => [$this->colaborador->id]],
        ]);

        $marcados = app(MarcarDiasAjustadosExistentes::class)->ejecutar();

        $this->assertSame(3, $marcados);
        $this->assertTrue($conMarca->fresh()->estaAjustadoManualmente());
        $this->assertTrue($conForzado->fresh()->estaAjustadoManualmente());
        $this->assertTrue($forzadoAntiguo->fresh()->estaAjustadoManualmente());
        $this->assertSame('presente', $forzadoAntiguo->fresh()->estado_forzado, 'Recupera el estado forzado que no se había guardado.');
        $this->assertSame($this->usuario->id, $forzadoAntiguo->fresh()->ajustado_por);
        $this->assertFalse($perdido->fresh()->estaAjustadoManualmente());
        $this->assertFalse($restablecido->fresh()->estaAjustadoManualmente());
    }
}
