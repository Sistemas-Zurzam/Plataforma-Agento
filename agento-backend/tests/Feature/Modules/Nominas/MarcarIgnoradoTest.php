<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Services\ImportarAntecedentesHistoricosService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarcarIgnoradoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function crearLoteConDetalle(string $estadoLote = 'validado'): array
    {
        $empresa = Empresa::factory()->create();
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => $estadoLote]);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'clasificacion' => 'observado', 'estado_validacion' => 'observado',
        ]);

        return [$empresa, $importacion, $detalle];
    }

    public function test_marca_la_fila_como_no_aplicable_ignorada_con_auditoria(): void
    {
        [$empresa, $importacion, $detalle] = $this->crearLoteConDetalle();
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $resultado = app(ImportarAntecedentesHistoricosService::class)->marcarIgnorado($empresa, $importacion, $detalle, 'No corresponde, es un ajuste contable interno', $usuarioId);

        $this->assertSame('no_aplicable', $resultado->clasificacion);
        $this->assertSame('ignorado', $resultado->estado_validacion);
        $this->assertDatabaseHas('nomina_importacion_historica_detalle_correcciones', [
            'detalle_id' => $detalle->id, 'campo' => 'marcado_ignorado',
        ]);
    }

    public function test_exige_motivo(): void
    {
        [$empresa, $importacion, $detalle] = $this->crearLoteConDetalle();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->marcarIgnorado($empresa, $importacion, $detalle, '', 1);
    }

    public function test_bloqueado_si_el_lote_ya_esta_aprobado(): void
    {
        [$empresa, $importacion, $detalle] = $this->crearLoteConDetalle('aprobado');

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->marcarIgnorado($empresa, $importacion, $detalle, 'Motivo', 1);
    }

    public function test_bloqueado_si_el_lote_ya_esta_aplicado(): void
    {
        [$empresa, $importacion, $detalle] = $this->crearLoteConDetalle('aplicado');

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->marcarIgnorado($empresa, $importacion, $detalle, 'Motivo', 1);
    }

    public function test_bloqueado_si_el_lote_ya_esta_anulado(): void
    {
        [$empresa, $importacion, $detalle] = $this->crearLoteConDetalle('anulado');

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->marcarIgnorado($empresa, $importacion, $detalle, 'Motivo', 1);
    }
}
