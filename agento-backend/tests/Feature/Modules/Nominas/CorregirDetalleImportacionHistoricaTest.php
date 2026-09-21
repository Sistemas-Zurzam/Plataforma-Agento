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
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class CorregirDetalleImportacionHistoricaTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function crearLoteConDetalle(array $atributosDetalle = []): array
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $importacion = NominaImportacionHistorica::factory()->create(['empresa_id' => $empresa->id, 'estado' => 'validado']);
        $detalle = NominaImportacionHistoricaDetalle::factory()->create([
            'importacion_id' => $importacion->id, 'empresa_id' => $empresa->id,
            'tipo_antecedente' => 'saldo_vacacional', 'clasificacion' => 'observado', 'estado_validacion' => 'observado',
            'colaborador_id' => null, ...$atributosDetalle,
        ]);

        return [$empresa, $colaborador, $importacion, $detalle];
    }

    public function test_rechaza_un_campo_fuera_de_la_lista_blanca(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['clasificacion' => 'aplicable'], 'Intento no autorizado', 1,
        );
    }

    public function test_rechaza_intentar_corregir_estado_validacion_directamente(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['estado_validacion' => 'valido'], 'Intento no autorizado', 1,
        );
    }

    /**
     * Endurecimiento pedido explícitamente: `datos_originales` es la
     * evidencia cruda del Excel y nunca debe poder modificarse, ni siquiera
     * por un intento explícito — permanece fuera de la lista blanca de
     * `corregir()` sin excepción.
     */
    public function test_rechaza_intentar_corregir_datos_originales(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle([
            'datos_originales' => ['documento' => '99999999', 'monto' => '100.00'],
        ]);
        $originalPrevio = $detalle->datos_originales;

        try {
            app(ImportarAntecedentesHistoricosService::class)->corregir(
                $empresa, $importacion, $detalle, ['datos_originales' => ['documento' => 'manipulado']], 'Intento no autorizado', 1,
            );
            $this->fail('Se esperaba que corregir datos_originales lanzara una excepción.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame($originalPrevio, $detalle->fresh()->datos_originales);
    }

    public function test_corregir_colaborador_id_recalcula_clasificacion_y_deja_auditoria(): void
    {
        [$empresa, $colaborador, $importacion, $detalle] = $this->crearLoteConDetalle();
        // Alinea la fila con el vínculo real del colaborador de prueba antes de vincularla.
        $detalle->update(['fecha_ingreso_vinculo' => $colaborador->fecha_ingreso]);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $corregido = app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['colaborador_id' => $colaborador->id], 'Vinculación manual tras revisión', $usuarioId,
        );

        $this->assertSame($colaborador->id, $corregido->colaborador_id);
        $this->assertDatabaseHas('nomina_importacion_historica_detalle_correcciones', [
            'detalle_id' => $detalle->id, 'campo' => 'colaborador_id', 'motivo' => 'Vinculación manual tras revisión',
        ]);
    }

    public function test_rechaza_vincular_un_colaborador_de_otra_empresa(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();
        $otraEmpresa = Empresa::factory()->create();
        $colaboradorAjeno = $this->crearColaborador($otraEmpresa);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['colaborador_id' => $colaboradorAjeno->id], 'Intento inválido', 1,
        );
    }

    public function test_bloqueado_si_el_lote_ya_esta_aprobado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();
        $importacion->update(['estado' => 'aprobado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['importe' => 100], 'Corrección tardía', 1,
        );
    }

    public function test_bloqueado_si_el_lote_ya_esta_aplicado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();
        $importacion->update(['estado' => 'aplicado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['importe' => 100], 'Corrección tardía', 1,
        );
    }

    public function test_bloqueado_si_el_lote_ya_esta_anulado(): void
    {
        [$empresa, , $importacion, $detalle] = $this->crearLoteConDetalle();
        $importacion->update(['estado' => 'anulado']);

        $this->expectException(ValidationException::class);
        app(ImportarAntecedentesHistoricosService::class)->corregir(
            $empresa, $importacion, $detalle, ['importe' => 100], 'Corrección tardía', 1,
        );
    }
}
