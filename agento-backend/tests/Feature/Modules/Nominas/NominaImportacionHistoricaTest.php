<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NominaImportacionHistoricaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_migracion_crea_la_tabla_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('nomina_importaciones_historicas'));
        $this->assertTrue(Schema::hasColumns('nomina_importaciones_historicas', [
            'empresa_id', 'archivo_nombre_original', 'archivo_hash', 'fecha_corte', 'estado',
            'filas_totales', 'filas_validas', 'filas_observadas', 'filas_con_errores', 'filas_aplicadas',
            'metadatos', 'resumen_errores',
            'cargado_por', 'cargado_at', 'validado_por', 'validado_at',
            'aprobado_por', 'aprobado_at', 'aplicado_por', 'aplicado_at',
            'anulado_por', 'anulado_at', 'motivo_anulacion',
        ]));
    }

    public function test_guarda_correctamente_sus_casts(): void
    {
        $importacion = NominaImportacionHistorica::factory()->create([
            'fecha_corte' => '2026-07-31',
            'metadatos' => ['hojas' => ['Gratificaciones', 'CTS']],
            'resumen_errores' => ['fila_10' => 'documento inválido'],
        ]);

        $this->assertSame('2026-07-31', $importacion->fresh()->fecha_corte->toDateString());
        $this->assertSame(['hojas' => ['Gratificaciones', 'CTS']], $importacion->fresh()->metadatos);
        $this->assertSame(['fila_10' => 'documento inválido'], $importacion->fresh()->resumen_errores);
    }

    public function test_pertenece_a_una_empresa_y_tiene_muchos_detalles(): void
    {
        $importacion = NominaImportacionHistorica::factory()->create();
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id,
            'empresa_id' => $importacion->empresa_id,
        ]);

        $this->assertTrue($importacion->empresa->is(Empresa::find($importacion->empresa_id)));
        $this->assertTrue($importacion->detalles->contains($detalle));
    }

    public function test_dos_empresas_distintas_pueden_usar_el_mismo_hash_de_archivo(): void
    {
        $hash = hash('sha256', 'contenido-identico');
        $empresaA = Empresa::factory()->create();
        $empresaB = Empresa::factory()->create();

        NominaImportacionHistorica::factory()->create(['empresa_id' => $empresaA->id, 'archivo_hash' => $hash]);
        $segundo = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresaB->id, 'archivo_hash' => $hash]);

        $this->assertDatabaseHas('nomina_importaciones_historicas', ['id' => $segundo->id, 'archivo_hash' => $hash]);
    }

    public function test_el_mismo_archivo_no_puede_aplicarse_dos_veces_en_la_misma_empresa(): void
    {
        $empresa = Empresa::factory()->create();
        $hash = hash('sha256', 'contenido-1');

        NominaImportacionHistorica::factory()->aplicado()->create(['empresa_id' => $empresa->id, 'archivo_hash' => $hash]);

        $this->expectException(QueryException::class);
        NominaImportacionHistorica::factory()->aplicado()->create(['empresa_id' => $empresa->id, 'archivo_hash' => $hash]);
    }

    /**
     * Un unique(empresa_id, archivo_hash) llano bloquearía este caso
     * legítimo (borrador fallido corregido y reintentado) — la idempotencia
     * real solo debe activarse al APLICAR, ver docblock de la migración.
     */
    public function test_un_reintento_de_un_archivo_no_aplicado_no_esta_bloqueado(): void
    {
        $empresa = Empresa::factory()->create();
        $hash = hash('sha256', 'contenido-fallido');

        NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'archivo_hash' => $hash, 'estado' => 'borrador']);
        $reintento = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'archivo_hash' => $hash, 'estado' => 'validado']);

        $this->assertDatabaseHas('nomina_importaciones_historicas', ['id' => $reintento->id]);
    }
}
