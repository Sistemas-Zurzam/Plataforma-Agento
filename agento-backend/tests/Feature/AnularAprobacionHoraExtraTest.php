<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaDecisionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class AnularAprobacionHoraExtraTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_una_hora_extra_aprobada_puede_anularse_con_motivo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador();
        $resultado = AsistenciaResultadoDiario::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha' => '2026-09-23', 'tipo_dia' => 'laborable_presencial',
            'estado' => 'presente', 'procesado_at' => now(),
        ]);
        $horaExtra = AsistenciaHoraExtra::create([
            'empresa_id' => $colaborador->empresa_id, 'resultado_diario_id' => $resultado->id,
            'colaborador_id' => $colaborador->id, 'fecha' => '2026-09-23',
            'minutos_observados' => 120, 'minutos_aprobados' => 120,
            'tasa' => '25', 'estado' => 'aprobado', 'motivo' => 'Aprobación accidental',
        ]);

        $anulada = app(AsistenciaDecisionService::class)->resolverHoraExtra(
            $colaborador->empresa, $horaExtra,
            ['accion' => 'anular_aprobacion', 'motivo' => 'Aprobado por error'],
            User::factory()->create(),
        );

        $this->assertSame('rechazado', $anulada->estado);
        $this->assertSame(0, $anulada->minutos_aprobados);
        $this->assertSame('Aprobado por error', $anulada->motivo);
    }
}
