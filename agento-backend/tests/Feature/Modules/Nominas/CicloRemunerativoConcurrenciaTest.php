<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fase A.2 del endurecimiento Asistencia-Nómina: crear()/actualizar() ahora
 * bloquean la fila de la empresa (lockForUpdate) dentro de una transacción
 * antes de verificar solapamiento. Este archivo no puede simular una carrera
 * real de dos requests concurrentes -- SQLite en memoria (phpunit.xml) no
 * comparte estado entre conexiones separadas, así que dos hilos reales
 * requieren MySQL con dos conexiones distintas, fuera del alcance de esta
 * suite. Lo que sí se verifica acá es que el comportamiento funcional de
 * verificarNoSolapa() se mantiene intacto ahora que vive dentro de la
 * transacción con lock -- la regresión más probable de este cambio.
 */
class CicloRemunerativoConcurrenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permite_crear_un_ciclo_solapado(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::factory()->create();
        $servicio = app(CicloRemunerativoService::class);

        $servicio->crear($empresa, [
            'nombre' => 'Julio 2026',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-31',
            'fecha_corte_asistencia' => '2026-07-31',
            'fecha_pago' => '2026-08-05',
        ], $usuario->id);

        $this->expectException(ValidationException::class);
        $servicio->crear($empresa, [
            'nombre' => 'Julio 2026 (duplicado)',
            'fecha_inicio' => '2026-07-15',
            'fecha_fin' => '2026-08-10',
            'fecha_corte_asistencia' => '2026-08-10',
            'fecha_pago' => '2026-08-15',
        ], $usuario->id);
    }

    public function test_permite_crear_ciclos_consecutivos_sin_solape(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::factory()->create();
        $servicio = app(CicloRemunerativoService::class);

        $julio = $servicio->crear($empresa, [
            'nombre' => 'Julio 2026',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-31',
            'fecha_corte_asistencia' => '2026-07-31',
            'fecha_pago' => '2026-08-05',
        ], $usuario->id);

        $agosto = $servicio->crear($empresa, [
            'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31',
            'fecha_pago' => '2026-09-05',
        ], $usuario->id);

        $this->assertNotSame($julio->id, $agosto->id);
        $this->assertDatabaseCount('ciclos_remunerativos', 2);
    }

    public function test_actualizar_no_permite_solaparse_con_otro_ciclo_existente(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::factory()->create();
        $servicio = app(CicloRemunerativoService::class);

        $servicio->crear($empresa, [
            'nombre' => 'Julio 2026',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin' => '2026-07-31',
            'fecha_corte_asistencia' => '2026-07-31',
            'fecha_pago' => '2026-08-05',
        ], $usuario->id);

        $agosto = $servicio->crear($empresa, [
            'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31',
            'fecha_pago' => '2026-09-05',
        ], $usuario->id);

        $this->expectException(ValidationException::class);
        $servicio->actualizar($empresa, $agosto, [
            'nombre' => 'Agosto (movido a julio)',
            'fecha_inicio' => '2026-07-20',
            'fecha_fin' => '2026-08-20',
            'fecha_corte_asistencia' => '2026-08-20',
            'fecha_pago' => '2026-08-25',
        ]);
    }
}
