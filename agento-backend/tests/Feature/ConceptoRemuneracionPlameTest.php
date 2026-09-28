<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\ConceptoRemuneracionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ConceptoRemuneracionPlameTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_descuento_compra_mercaderia_tiene_codigo_plame_0706_por_defecto(): void
    {
        $this->seed(DatabaseSeeder::class);

        $concepto = ConceptoRemuneracion::where('codigo', 'DESCUENTO_COMPRA_MERCADERIA')->firstOrFail();

        $this->assertSame('0706', $concepto->codigo_plame);
        $this->assertDatabaseHas('concepto_codigos_plame', [
            'concepto_remuneracion_id' => $concepto->id,
            'codigo_plame' => '0706',
        ]);
    }

    public function test_al_configurar_codigo_completa_solo_snapshots_vacios_del_concepto(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 1500, 'dias_pagados' => 30,
            'total_ingresos' => 1500, 'total_egresos' => 100, 'total_aportaciones' => 135,
            'neto_a_pagar' => 1400, 'estado' => 'pagada', 'es_version_vigente' => true,
            'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        $concepto = ConceptoRemuneracion::where('codigo', 'DESCUENTO_ERROR_OPERATIVO')->firstOrFail();
        $vacia = $boleta->conceptos()->create([
            'concepto_id' => $concepto->id, 'tipo' => 'egreso', 'es_remunerativo_laboral' => false,
            'afecta_renta_5ta' => false, 'monto' => 50, 'codigo_plame_snapshot' => null,
        ]);
        $clasificada = $boleta->conceptos()->create([
            'concepto_id' => $concepto->id, 'tipo' => 'egreso', 'es_remunerativo_laboral' => false,
            'afecta_renta_5ta' => false, 'monto' => 50, 'codigo_plame_snapshot' => '0704',
        ]);

        $servicio = app(ConceptoRemuneracionService::class);
        $servicio->actualizarCodigoPlame($concepto, ['codigo_plame' => '706']);

        $this->assertSame('0706', $vacia->fresh()->codigo_plame_snapshot);
        $this->assertSame('0704', $clasificada->fresh()->codigo_plame_snapshot);

        $otraVacia = $boleta->conceptos()->create([
            'concepto_id' => $concepto->id, 'tipo' => 'egreso', 'es_remunerativo_laboral' => false,
            'afecta_renta_5ta' => false, 'monto' => 25, 'codigo_plame_snapshot' => null,
        ]);
        $historialAntes = $concepto->codigosPlameHistorial()->count();
        $servicio->actualizarCodigoPlame($concepto->fresh(), ['codigo_plame' => '0706']);

        $this->assertSame('0706', $otraVacia->fresh()->codigo_plame_snapshot);
        $this->assertSame($historialAntes, $concepto->codigosPlameHistorial()->count());
    }
}
