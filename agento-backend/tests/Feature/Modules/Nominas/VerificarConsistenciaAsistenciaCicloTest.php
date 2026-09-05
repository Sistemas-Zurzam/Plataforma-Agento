<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\VerificarConsistenciaAsistenciaCiclo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Incremento 2 del endurecimiento Asistencia-Nómina (A.1, primeros 3
 * chequeos). No cubre incidencias/horas extra pendientes ni
 * requiere_recalculo -- eso es el incremento 4.
 */
class VerificarConsistenciaAsistenciaCicloTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private function crearResultadoDiario(Empresa $empresa, int $colaboradorId, string $fecha): void
    {
        AsistenciaResultadoDiario::create([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaboradorId,
            'fecha' => $fecha,
            'tipo_dia' => 'laborable_presencial',
            'estado' => 'presente',
            'minutos_programados' => 480,
            'minutos_trabajados' => 480,
            'procesado_at' => now(),
        ]);
    }

    public function test_rechaza_fechas_sin_ningun_periodo_de_asistencia_asociado(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Empresa aislada (no la sembrada por DatabaseSeeder) -- así el
        // chequeo de cobertura solo ve a los colaboradores que este test
        // crea explícitamente, sin verse afectado por datos de otros
        // colaboradores sembrados en la empresa compartida.
        $empresa = Empresa::factory()->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no pertenecen a ningún período de asistencia');
        app(VerificarConsistenciaAsistenciaCiclo::class)->verificar($empresa, '2026-07-01', '2026-07-31');
    }

    public function test_rechaza_si_hay_un_periodo_de_asistencia_abierto_superpuesto(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Empresa aislada (no la sembrada por DatabaseSeeder) -- así el
        // chequeo de cobertura solo ve a los colaboradores que este test
        // crea explícitamente, sin verse afectado por datos de otros
        // colaboradores sembrados en la empresa compartida.
        $empresa = Empresa::factory()->create();

        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-31', 'estado' => 'abierto',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sigue abierto');
        app(VerificarConsistenciaAsistenciaCiclo::class)->verificar($empresa, '2026-07-01', '2026-07-31');
    }

    public function test_rechaza_si_falta_cobertura_de_asistencia_para_algun_colaborador(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Empresa aislada (no la sembrada por DatabaseSeeder) -- así el
        // chequeo de cobertura solo ve a los colaboradores que este test
        // crea explícitamente, sin verse afectado por datos de otros
        // colaboradores sembrados en la empresa compartida.
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);

        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-02', 'estado' => 'cerrado',
        ]);
        // Solo se cubre el primer día -- el segundo queda faltante a propósito.
        $this->crearResultadoDiario($empresa, $colaborador->id, '2026-07-01');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sin asistencia procesada');
        app(VerificarConsistenciaAsistenciaCiclo::class)->verificar($empresa, '2026-07-01', '2026-07-02');
    }

    public function test_pasa_sin_excepcion_cuando_el_periodo_esta_cerrado_y_la_cobertura_esta_completa(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Empresa aislada (no la sembrada por DatabaseSeeder) -- así el
        // chequeo de cobertura solo ve a los colaboradores que este test
        // crea explícitamente, sin verse afectado por datos de otros
        // colaboradores sembrados en la empresa compartida.
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01']);

        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-02', 'estado' => 'cerrado',
        ]);
        $this->crearResultadoDiario($empresa, $colaborador->id, '2026-07-01');
        $this->crearResultadoDiario($empresa, $colaborador->id, '2026-07-02');

        app(VerificarConsistenciaAsistenciaCiclo::class)->verificar($empresa, '2026-07-01', '2026-07-02');

        $this->assertTrue(true); // no lanzó excepción
    }

    public function test_ignora_colaboradores_fuera_de_su_vigencia_laboral(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Empresa aislada (no la sembrada por DatabaseSeeder) -- así el
        // chequeo de cobertura solo ve a los colaboradores que este test
        // crea explícitamente, sin verse afectado por datos de otros
        // colaboradores sembrados en la empresa compartida.
        $empresa = Empresa::factory()->create();
        // Ingresa recién el 2026-07-02 -- el 01 no le corresponde cobertura.
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-07-02']);

        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id, 'fecha_inicio' => '2026-07-01', 'fecha_fin' => '2026-07-02', 'estado' => 'cerrado',
        ]);
        $this->crearResultadoDiario($empresa, $colaborador->id, '2026-07-02');

        app(VerificarConsistenciaAsistenciaCiclo::class)->verificar($empresa, '2026-07-01', '2026-07-02');

        $this->assertTrue(true); // no lanzó excepción
    }
}
