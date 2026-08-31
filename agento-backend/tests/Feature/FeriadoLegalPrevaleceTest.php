<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Application\ResolverJornadaDiaria;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class FeriadoLegalPrevaleceTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_feriado_legal_prevalece_sobre_calendario_manual_laborable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'fecha_ingreso' => '2026-01-01',
        ]);

        ColaboradorCalendarioDia::create([
            'colaborador_id' => $colaborador->id,
            'fecha' => '2026-08-06',
            'tipo' => 'laborable_presencial',
            'origen' => ColaboradorCalendarioDia::ORIGEN_MANUAL,
        ]);

        $jornada = app(ResolverJornadaDiaria::class)->resolver(
            $colaborador,
            Carbon::parse('2026-08-06'),
        );

        $this->assertSame('feriado', $jornada['tipo_dia']);

        $calendario = app(ColaboradorService::class)->calendarioDelMes(
            $colaborador->empresa,
            $colaborador,
            2026,
            8,
        );
        $seisDeAgosto = collect($calendario['dias'])->firstWhere('fecha', '2026-08-06');

        $this->assertSame('feriado', $seisDeAgosto['tipo']);
    }

    public function test_guardado_no_puede_convertir_feriado_legal_en_laborable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'fecha_ingreso' => '2026-01-01',
        ]);

        app(ColaboradorService::class)->actualizarCalendario(
            $colaborador->empresa,
            $colaborador,
            [['fecha' => '2026-08-06', 'tipo' => 'laborable_presencial']],
        );

        $diaGuardado = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha', '2026-08-06')
            ->firstOrFail();

        $this->assertSame('feriado', $diaGuardado->tipo);
        $this->assertSame(ColaboradorCalendarioDia::ORIGEN_FERIADO_AUTOMATICO, $diaGuardado->origen);
    }
}
