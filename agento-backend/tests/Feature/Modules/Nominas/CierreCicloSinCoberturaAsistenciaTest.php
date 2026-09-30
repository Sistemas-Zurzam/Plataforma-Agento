<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class CierreCicloSinCoberturaAsistenciaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_cierra_ciclo_con_boletas_aprobadas_aunque_asistencia_siga_abierta_y_sin_cobertura(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id,
            'nombre' => 'Planilla septiembre 2026',
            'periodicidad' => 'mensual',
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-30',
            'fecha_corte_asistencia' => '2026-09-30',
            'fecha_pago' => '2026-09-30',
            'estado' => 'calculado',
        ]);
        Boleta::create([
            'ciclo_id' => $ciclo->id,
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'version' => 1,
            'es_version_vigente' => true,
            'estado' => 'aprobada',
            'regimen_laboral_snapshot' => 'General',
            'sueldo_basico_snapshot' => 3000,
            'dias_pagados' => 30,
            'total_ingresos' => 3000,
            'total_egresos' => 0,
            'total_aportaciones' => 0,
            'neto_a_pagar' => 3000,
            'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test',
            'calculado_at' => now(),
        ]);
        AsistenciaPeriodo::create([
            'empresa_id' => $empresa->id,
            'fecha_inicio' => '2026-09-01',
            'fecha_fin' => '2026-09-30',
            'estado' => 'abierto',
        ]);

        $cerrado = app(CicloRemunerativoService::class)->cerrar($empresa, $ciclo);

        $this->assertSame('cerrado', $cerrado->estado);
        $this->assertDatabaseHas('boleta_datos_pago', ['boleta_id' => $ciclo->boletas()->firstOrFail()->id]);
    }
}
