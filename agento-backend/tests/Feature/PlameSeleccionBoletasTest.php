<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\Plame\PlameCicloDatosLoader;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class PlameSeleccionBoletasTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_carga_solo_las_boletas_seleccionadas_para_plame(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $primero = $this->crearColaborador($empresa, ['numero_documento' => '70000001']);
        $segundo = $this->crearColaborador($empresa, ['numero_documento' => '70000002']);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boletaUno = $this->crearBoleta($empresa, $ciclo, $primero->id);
        $boletaDos = $this->crearBoleta($empresa, $ciclo, $segundo->id);

        $seleccion = PlameCicloDatosLoader::boletasPlanilla($ciclo, [$boletaDos->id]);

        $this->assertCount(1, $seleccion);
        $this->assertSame($boletaDos->id, $seleccion->first()->id);
        $this->assertNotSame($boletaUno->id, $seleccion->first()->id);
    }

    private function crearBoleta(Empresa $empresa, CicloRemunerativo $ciclo, int $colaboradorId): Boleta
    {
        return Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaboradorId,
            'regimen_laboral_snapshot' => 'Micro Empresa', 'sueldo_basico_snapshot' => 1500,
            'dias_pagados' => 30, 'total_ingresos' => 1500, 'total_egresos' => 100,
            'total_aportaciones' => 135, 'neto_a_pagar' => 1400, 'estado' => 'pagada',
            'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
    }
}
