<?php

namespace Tests\Feature;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Pre-cálculo de un rotativo antes del corte: los días de hoy en adelante
 * sin resultado aún no bloquean; los días pasados sin rol, o cualquier día
 * procesado como "sin clasificar", sí.
 */
class RotativoPreCalculoTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private const HOY = '2026-10-23';

    private function rotativoConAsistenciaHasta(string $ultimoDia, array $diasFaltantes = []): Colaborador
    {
        $this->travelTo(Carbon::parse(self::HOY.' 12:00:00'));
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);

        $colaborador = $this->crearColaborador($empresa);
        $horario = Horario::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Rotativo Test', 'tipo_turno' => 'rotativo',
            'vigencia_desde' => '2026-01-01', 'activo' => true,
        ]);
        ColaboradorHorarioAsignacion::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id, 'horario_id' => $horario->id,
            'dias_descanso_rotativo_por_semana' => 1, 'vigencia_desde' => '2026-01-01', 'vigencia_hasta' => null,
        ]);

        for ($fecha = Carbon::parse('2026-10-01'); $fecha->lte(Carbon::parse($ultimoDia)); $fecha->addDay()) {
            if (in_array($fecha->toDateString(), $diasFaltantes, true)) {
                continue;
            }
            $this->resultado($colaborador, $fecha->toDateString(), 'presente');
        }

        return $colaborador;
    }

    private function resultado(Colaborador $colaborador, string $fecha, string $estado): void
    {
        AsistenciaResultadoDiario::create([
            'empresa_id' => $colaborador->empresa_id, 'colaborador_id' => $colaborador->id,
            'fecha' => $fecha, 'tipo_dia' => 'laborable', 'estado' => $estado, 'procesado_at' => now(),
        ]);
    }

    private function calcularOctubre(Colaborador $colaborador): array
    {
        return app(CalcularBoletaColaborador::class)->calcular($colaborador->fresh(), '2026-10-01', '2026-10-31', '2026-10-31');
    }

    public function test_los_dias_que_aun_no_ocurren_no_bloquean_el_pre_calculo(): void
    {
        $colaborador = $this->rotativoConAsistenciaHasta('2026-10-22');

        $calculo = $this->calcularOctubre($colaborador);

        $this->assertEquals(30, $calculo['dias_pagados']);
    }

    public function test_un_dia_pasado_sin_rol_sigue_bloqueando(): void
    {
        $colaborador = $this->rotativoConAsistenciaHasta('2026-10-22', ['2026-10-10']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026-10-10');
        $this->calcularOctubre($colaborador);
    }

    public function test_un_dia_procesado_sin_clasificar_bloquea_aunque_sea_hoy(): void
    {
        $colaborador = $this->rotativoConAsistenciaHasta('2026-10-22');
        $this->resultado($colaborador, self::HOY, AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(self::HOY);
        $this->calcularOctubre($colaborador);
    }
}
