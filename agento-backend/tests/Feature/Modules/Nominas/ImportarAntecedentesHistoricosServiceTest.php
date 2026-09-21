<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Services\ImportarAntecedentesHistoricosService;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ImportarAntecedentesHistoricosServiceTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class); // User::factory() depende del rol por defecto
    }

    private function subirFixture(string $nombre): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/{$nombre}"), $nombre, null, null, true);
    }

    public function test_importar_clasifica_las_filas_y_deja_el_lote_validado(): void
    {
        $empresa = Empresa::factory()->create(['nombre_comercial' => 'EMPRESA FIXTURE SA']);
        $this->crearColaborador($empresa, ['numero_documento' => '10000001', 'fecha_ingreso' => '2020-01-01']);
        $this->crearColaborador($empresa, ['numero_documento' => '10000002', 'fecha_ingreso' => '2021-03-01']);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $importacion = app(ImportarAntecedentesHistoricosService::class)->importar(
            $empresa, $this->subirFixture('antecedentes_historicos_validos.xlsx'), '2026-07-31', $usuarioId,
        );

        $this->assertSame('validado', $importacion->estado);
        // Data Planilla trae 5 filas (4 de la empresa del lote + 1 de "otra empresa", nunca
        // persistida) + 1 fila de Data Provisiones = 5 filas persistidas en total.
        $this->assertSame(5, $importacion->filas_totales);
        $this->assertSame(1, $importacion->filas_otra_empresa);

        $detalles = NominaImportacionHistoricaDetalle::where('importacion_id', $importacion->id)->get();
        $this->assertSame(5, $detalles->count());

        $gratificacionCancelada = $detalles->firstWhere('nombre_concepto_original', 'GRATIFICACION ORDINARIA');
        $this->assertSame('observado', $gratificacionCancelada->clasificacion);
        $this->assertSame('observado', $gratificacionCancelada->estado_validacion);
        $this->assertSame($this->colaboradorIdPorDocumento($empresa, '10000001'), $gratificacionCancelada->colaborador_id);

        $basico = $detalles->firstWhere('nombre_concepto_original', 'BASICO');
        $this->assertSame('no_aplicable', $basico->clasificacion);
        $this->assertSame('ignorado', $basico->estado_validacion);

        $provision = $detalles->firstWhere('hoja_nombre', 'Data Provisiones');
        $this->assertSame('no_aplicable', $provision->clasificacion);

        // Ninguna fila de "OTRA EMPRESA SA" existe en la base -- ni siquiera para trazabilidad.
        $this->assertDatabaseMissing('nomina_importacion_historica_detalles', ['colaborador_nombre_original' => 'OTRO COLABORADOR']);
    }

    public function test_aprobar_queda_bloqueado_si_existe_una_fila_con_error(): void
    {
        $empresa = Empresa::factory()->create(['nombre_comercial' => 'EMPRESA FIXTURE SA']);
        $this->crearColaborador($empresa, ['numero_documento' => '10000001', 'fecha_ingreso' => '2020-01-01']);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $servicio = app(ImportarAntecedentesHistoricosService::class);
        $importacion = $servicio->importar($empresa, $this->subirFixture('antecedentes_historicos_con_errores.xlsx'), '2026-07-31', $usuarioId);

        $this->assertGreaterThan(0, $importacion->filas_con_errores);

        $this->expectException(ValidationException::class);
        $servicio->aprobar($empresa, $importacion, $usuarioId);
    }

    public function test_aprobar_no_se_bloquea_por_filas_observadas_o_ignoradas(): void
    {
        $empresa = Empresa::factory()->create(['nombre_comercial' => 'EMPRESA FIXTURE SA']);
        $this->crearColaborador($empresa, ['numero_documento' => '10000001', 'fecha_ingreso' => '2020-01-01']);
        $this->crearColaborador($empresa, ['numero_documento' => '10000002', 'fecha_ingreso' => '2021-03-01']);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $servicio = app(ImportarAntecedentesHistoricosService::class);
        $importacion = $servicio->importar($empresa, $this->subirFixture('antecedentes_historicos_validos.xlsx'), '2026-07-31', $usuarioId);
        $this->assertSame(0, $importacion->filas_con_errores);

        $aprobado = $servicio->aprobar($empresa, $importacion, $usuarioId);

        $this->assertSame('aprobado', $aprobado->estado);
    }

    /**
     * Endurecimiento pedido explícitamente: si el lector no puede
     * interpretar el archivo, `importar()` no debe dejar ningún lote a
     * medias. Como la lectura ocurre antes de abrir la transacción, un
     * archivo irreconocible no debe crear ninguna fila en absoluto.
     */
    public function test_un_error_del_lector_no_deja_ningun_lote_a_medias(): void
    {
        $empresa = Empresa::factory()->create(['nombre_comercial' => 'EMPRESA FIXTURE SA']);
        $usuarioId = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $archivoCorrupto = UploadedFile::fake()->create('corrupto.xlsx', 5);

        $conteoAntes = NominaImportacionHistorica::count();

        try {
            app(ImportarAntecedentesHistoricosService::class)->importar($empresa, $archivoCorrupto, '2026-07-31', $usuarioId);
            $this->fail('Se esperaba que un archivo irreconocible lanzara una excepción.');
        } catch (\Throwable) {
            // esperado: el lector no puede identificar el formato del archivo.
        }

        $this->assertSame($conteoAntes, NominaImportacionHistorica::count());
    }

    private function colaboradorIdPorDocumento(Empresa $empresa, string $documento): ?int
    {
        return Colaborador::where('empresa_id', $empresa->id)
            ->where('numero_documento', $documento)->value('id');
    }
}
