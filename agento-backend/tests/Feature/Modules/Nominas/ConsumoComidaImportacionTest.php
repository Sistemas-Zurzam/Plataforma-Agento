<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Application\ConsumoComida\ConsumoComidaImportacionService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\ConsumoComidaAplicacion;
use App\Modules\Nominas\Models\ConsumoComidaDetalle;
use App\Modules\Nominas\Models\ConsumoComidaImportacion;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\CicloRemunerativoService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Importación de consumos de comida → descuento del ciclo. Usa el motor real
 * (BoletaService::calcularPlanilla) para comprobar que el descuento entra en
 * la boleta una sola vez.
 */
class ConsumoComidaImportacionTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        ParametrosVigentesResolver::limpiarCache();
        $empresa = Empresa::firstOrFail();
        // Parámetros laborales (RMV, tasas) que el motor exige para calcular.
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $ciclo = $this->ciclo($empresa);
        $ana = $this->crearColaborador($empresa, ['numero_documento' => '00123456', 'fecha_ingreso' => '2026-01-01', 'nombres' => 'Ana']);
        $luis = $this->crearColaborador($empresa, ['numero_documento' => '71234567', 'fecha_ingreso' => '2026-01-01', 'nombres' => 'Luis']);

        return [$empresa, $usuario, $ciclo, $ana, $luis, app(ConsumoComidaImportacionService::class)];
    }

    private function ciclo(Empresa $empresa, string $estado = 'abierto'): CicloRemunerativo
    {
        return CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Octubre 2026',
            'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
            'fecha_corte_asistencia' => '2026-10-31', 'fecha_pago' => '2026-11-05',
            'estado' => $estado,
        ]);
    }

    /** @param list<array<int, mixed>> $filas documento, fecha, concepto, monto, referencia */
    private function excel(array $filas, string $nombre = 'consumos.xlsx'): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Consumos');
        $hoja->fromArray(['documento', 'fecha_consumo', 'concepto', 'monto', 'referencia'], null, 'A1');
        foreach ($filas as $indice => $fila) {
            $numero = $indice + 2;
            foreach (['A', 'B', 'C', 'D', 'E'] as $posicion => $letra) {
                $valor = $fila[$posicion] ?? null;
                if ($valor === null) {
                    continue;
                }
                if (is_string($valor) && str_starts_with($valor, '=')) {
                    $hoja->setCellValueExplicit("{$letra}{$numero}", $valor, DataType::TYPE_FORMULA);
                } elseif (is_string($valor)) {
                    $hoja->setCellValueExplicit("{$letra}{$numero}", $valor, DataType::TYPE_STRING);
                } else {
                    $hoja->setCellValue("{$letra}{$numero}", $valor);
                }
            }
        }
        $ruta = tempnam(sys_get_temp_dir(), 'consumo').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importar(ConsumoComidaImportacionService $service, Empresa $empresa, CicloRemunerativo $ciclo, User $usuario, array $filas, string $nombre = 'consumos.xlsx'): ConsumoComidaImportacion
    {
        return $service->importar($empresa, $ciclo, $this->excel($filas, $nombre), 'PLANTILLA', null, $usuario->id);
    }

    private function descuentoEnBoleta(CicloRemunerativo $ciclo, int $colaboradorId): array
    {
        $boleta = Boleta::where('ciclo_id', $ciclo->id)->where('colaborador_id', $colaboradorId)->where('es_version_vigente', true)->firstOrFail();
        $conceptoId = ConceptoRemuneracion::where('codigo', 'DESCUENTO_CONSUMO_COMIDA')->value('id');

        return BoletaConcepto::where('boleta_id', $boleta->id)->where('concepto_id', $conceptoId)->pluck('monto')->map(fn ($m) => (string) $m)->all();
    }

    public function test_varias_filas_del_mismo_colaborador_con_ceros_iniciales_se_suman_en_un_descuento(): void
    {
        [$empresa, $usuario, $ciclo, $ana, $luis, $service] = $this->escenario();

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [
            ['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1'],
            ['00123456', 46301, 'Almuerzo', 10.25, 'T-2'],
            ['71234567', '2026-10-05', 'Desayuno', '6', 'T-3'],
        ]);

        $this->assertSame(ConsumoComidaImportacion::ESTADO_VALIDADA, $importacion->estado);
        $this->assertSame('28.75', (string) $importacion->monto_total);
        $this->assertSame(2, $importacion->colaboradores_total);
        $this->assertSame($ana->id, ConsumoComidaDetalle::where('importacion_id', $importacion->id)->where('fila', 2)->value('colaborador_id'));

        $service->aplicar($empresa, $importacion, $usuario->id);

        $this->assertSame('22.75', (string) ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->where('colaborador_id', $ana->id)->value('monto'));
        $this->assertSame('6.00', (string) ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->where('colaborador_id', $luis->id)->value('monto'));
    }

    public function test_fechas_montos_y_formulas_invalidos_dejan_errores_por_fila_sin_aplicacion_parcial(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [
            ['00123456', '2026-10-05', 'Almuerzo', '12.50', null],
            ['00123456', '05/10/2026', 'Almuerzo', '12.50', null],
            ['00123456', '2026-11-05', 'Almuerzo', '12.50', null],
            ['00123456', '2026-10-06', 'Almuerzo', '-3', null],
            ['00123456', '2026-10-07', 'Almuerzo', '1.234', null],
            ['00123456', '2026-10-08', 'Almuerzo', '=1+1', null],
            ['99999999', '2026-10-09', 'Almuerzo', '5', null],
            [123456, '2026-10-10', 'Almuerzo', '5', null],
        ]);

        $this->assertSame(ConsumoComidaImportacion::ESTADO_CON_ERRORES, $importacion->estado);
        $this->assertSame(7, $importacion->filas_con_error);
        $this->assertSame(ConsumoComidaDetalle::ESTADO_VALIDO, ConsumoComidaDetalle::where('importacion_id', $importacion->id)->where('fila', 2)->value('estado'));

        try {
            $service->aplicar($empresa, $importacion, $usuario->id);
            $this->fail('Una importación con errores no debe aplicarse.');
        } catch (ValidationException) {
            // esperado
        }
        $this->assertSame(0, ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->count());
    }

    public function test_colaborador_cesado_dentro_del_ciclo_si_recibe_el_descuento(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();
        $cesado = $this->crearColaborador($empresa, [
            'numero_documento' => '40000001', 'fecha_ingreso' => '2026-01-01', 'fecha_cese' => '2026-10-15', 'activo' => false,
        ]);

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [['40000001', '2026-10-10', 'Almuerzo', '8.00', null]]);

        $this->assertSame(ConsumoComidaImportacion::ESTADO_VALIDADA, $importacion->estado);
        $this->assertSame($cesado->id, ConsumoComidaDetalle::where('importacion_id', $importacion->id)->value('colaborador_id'));
    }

    public function test_archivo_repetido_con_otro_nombre_no_se_puede_volver_a_aplicar(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();
        $filas = [['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1']];

        $primera = $this->importar($service, $empresa, $ciclo, $usuario, $filas, 'consumos.xlsx');
        $service->aplicar($empresa, $primera, $usuario->id);

        $segunda = $this->importar($service, $empresa, $ciclo, $usuario, $filas, 'otro-nombre.xlsx');

        $this->assertSame(ConsumoComidaImportacion::ESTADO_CON_ERRORES, $segunda->estado);
        $this->assertNotEmpty($segunda->errores_generales);
        $this->assertStringContainsString('ya fue aplicada', implode(' ', ConsumoComidaDetalle::where('importacion_id', $segunda->id)->value('errores')));
    }

    public function test_referencias_repetidas_en_el_archivo_son_error(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [
            ['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1'],
            ['71234567', '2026-10-06', 'Almuerzo', '9.00', 'T-1'],
        ]);

        $this->assertSame(2, $importacion->filas_con_error);
    }

    public function test_posible_duplicado_sin_referencia_exige_revision_con_motivo(): void
    {
        [$empresa, $usuario, $ciclo, $ana, , $service] = $this->escenario();

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [
            ['00123456', '2026-10-05', 'Almuerzo', '12.50', null],
            ['00123456', '2026-10-05', 'almuerzo', '12.50', null],
        ]);
        $this->assertSame(ConsumoComidaImportacion::ESTADO_VALIDADA, $importacion->estado);
        $this->assertSame(2, $importacion->filas_posible_duplicado);

        try {
            $service->aplicar($empresa, $importacion, $usuario->id);
            $this->fail('Los posibles duplicados sin revisar deben bloquear la confirmación.');
        } catch (ValidationException) {
            // esperado
        }

        $ids = ConsumoComidaDetalle::where('importacion_id', $importacion->id)->pluck('id')->all();
        $service->admitirDuplicados($empresa, $importacion->fresh(), $ids, 'Almorzó dos veces ese día (turno doble).', $usuario->id);
        $service->aplicar($empresa, $importacion->fresh(), $usuario->id);

        $this->assertSame('25.00', (string) ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->where('colaborador_id', $ana->id)->value('monto'));
    }

    public function test_confirmacion_repetida_no_duplica_descuentos(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();
        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1']]);

        $service->aplicar($empresa, $importacion, $usuario->id);
        $service->aplicar($empresa, $importacion->fresh(), $usuario->id);
        $service->aplicar($empresa, $importacion, $usuario->id);

        $this->assertSame(1, ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->count());
        $this->assertSame(1, ConsumoComidaAplicacion::where('importacion_id', $importacion->id)->count());
    }

    public function test_recalcular_incorpora_el_descuento_una_sola_vez_y_anular_solo_retira_su_importacion(): void
    {
        [$empresa, $usuario, $ciclo, $ana, , $service] = $this->escenario();
        $boletas = app(BoletaService::class);
        $this->cubrirAsistencia($empresa, '2026-10-01', '2026-10-31');

        $primera = $this->importar($service, $empresa, $ciclo, $usuario, [['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1']]);
        $segunda = $this->importar($service, $empresa, $ciclo, $usuario, [['00123456', '2026-10-06', 'Cena', '7.25', 'T-2']], 'segunda.xlsx');
        $service->aplicar($empresa, $primera, $usuario->id);
        $service->aplicar($empresa, $segunda, $usuario->id);

        $boletas->calcularPlanilla($empresa, $ciclo->fresh(), $usuario->id);
        $boletas->calcularPlanilla($empresa, $ciclo->fresh(), $usuario->id, 'Recalculo de prueba');

        $this->assertEqualsCanonicalizing(['12.50', '7.25'], $this->descuentoEnBoleta($ciclo, $ana->id));
        $this->assertSame(0, ConsumoComidaAplicacion::where('ciclo_id', $ciclo->id)->pendientesDeRecalculo()->count());
        $this->assertNotNull(ConsumoComidaAplicacion::where('importacion_id', $primera->id)->value('boleta_id'));

        $service->anular($empresa, $primera->fresh(), 'Archivo cargado con montos de prueba.', $usuario->id);

        $this->assertTrue($ciclo->fresh()->requiere_recalculo);
        $this->assertSame(1, ConsumoComidaAplicacion::where('ciclo_id', $ciclo->id)->pendientesDeRecalculo()->count());
        $this->assertSame(1, ConsumoComidaDetalle::where('importacion_id', $primera->id)->count(), 'Los consumos anulados se conservan.');

        $boletas->calcularPlanilla($empresa, $ciclo->fresh(), $usuario->id, 'Tras anular');

        $this->assertSame(['7.25'], $this->descuentoEnBoleta($ciclo, $ana->id));
        $this->assertNotNull(ConsumoComidaAplicacion::where('importacion_id', $primera->id)->value('boleta_retiro_id'));
    }

    public function test_no_se_puede_cerrar_el_ciclo_con_descuentos_pendientes_ni_aplicar_en_ciclo_cerrado_o_pagado(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();
        $boletas = app(BoletaService::class);
        $this->cubrirAsistencia($empresa, '2026-10-01', '2026-10-31');
        $boletas->calcularPlanilla($empresa, $ciclo->fresh(), $usuario->id);
        Boleta::where('ciclo_id', $ciclo->id)->where('es_version_vigente', true)->update(['estado' => 'aprobada']);

        $importacion = $this->importar($service, $empresa, $ciclo, $usuario, [['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1']]);
        try {
            $service->aplicar($empresa, $importacion, $usuario->id);
            $this->fail('Con boletas aprobadas debe exigirse confirmación explícita.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmar_boletas_aprobadas', $e->errors());
        }
        $service->aplicar($empresa, $importacion->fresh(), $usuario->id, confirmarBoletasAprobadas: true);

        try {
            app(CicloRemunerativoService::class)->cerrar($empresa, $ciclo->fresh());
            $this->fail('El cierre debe bloquearse mientras haya descuentos sin incorporar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('consumo de comida', implode(' ', $e->errors()['estado']));
        }
        $this->assertSame('calculado', $ciclo->fresh()->estado);

        foreach (['cerrado', 'pagado'] as $estado) {
            $otroCiclo = CicloRemunerativo::create([
                'empresa_id' => $empresa->id, 'nombre' => "Noviembre {$estado}",
                'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-30',
                'fecha_corte_asistencia' => '2026-11-30', 'fecha_pago' => '2026-12-05', 'estado' => 'abierto',
            ]);
            $pendiente = $this->importar($service, $empresa, $otroCiclo, $usuario, [['00123456', '2026-11-05', 'Almuerzo', '5.00', "N-{$estado}"]]);
            $otroCiclo->update(['estado' => $estado]);
            try {
                $service->aplicar($empresa, $pendiente, $usuario->id);
                $this->fail("Un ciclo {$estado} no admite aplicar descuentos.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('ciclo', $e->errors());
            }
            $otroCiclo->delete();
        }
    }

    public function test_aislamiento_entre_empresas_y_restriccion_del_gerente(): void
    {
        [$empresa, $usuario, $ciclo, , , $service] = $this->escenario();
        $otraEmpresa = Empresa::factory()->create();
        $cicloOtra = $this->ciclo($otraEmpresa);
        $this->crearColaborador($otraEmpresa, ['numero_documento' => '00123456', 'fecha_ingreso' => '2026-01-01']);
        $importacionOtra = $this->importar($service, $otraEmpresa, $cicloOtra, $usuario, [['00123456', '2026-10-05', 'Almuerzo', '12.50', null]]);

        // El documento se resuelve dentro de la empresa del ciclo, nunca en otra.
        $this->assertNotSame(
            ConsumoComidaDetalle::where('importacion_id', $importacionOtra->id)->value('colaborador_id'),
            Colaborador::where('empresa_id', $empresa->id)->where('numero_documento', '00123456')->value('id'),
        );

        $rol = Role::create(['clave' => 'nomina_prueba', 'nombre' => 'Nómina prueba']);
        $rol->permissions()->sync(Permission::whereIn('clave', ['nominas.ver', 'nominas.gestionar_ciclos'])->pluck('id'));
        $operador = $this->usuarioConRol($empresa, $rol);

        $this->withHeaders($operador)->getJson("/api/ciclos-remunerativos/{$ciclo->id}/consumos-comida")->assertOk();
        $this->withHeaders($operador)->getJson("/api/ciclos-remunerativos/{$cicloOtra->id}/consumos-comida")->assertForbidden();
        $this->withHeaders($operador)->getJson("/api/consumos-comida/importaciones/{$importacionOtra->id}")->assertForbidden();
        $this->withHeaders($operador)->postJson("/api/consumos-comida/importaciones/{$importacionOtra->id}/aplicar")->assertForbidden();
        $this->withHeaders($operador)->get("/api/consumos-comida/importaciones/{$importacionOtra->id}/archivo")->assertForbidden();

        $gerente = $this->usuarioConRol($empresa, Role::where('clave', 'gerencia')->firstOrFail());
        $this->withHeaders($gerente)->getJson("/api/ciclos-remunerativos/{$ciclo->id}/consumos-comida")->assertForbidden();
        $this->withHeaders($gerente)->getJson('/api/consumos-comida/plantilla')->assertForbidden();
        $this->withHeaders($gerente)->postJson("/api/ciclos-remunerativos/{$ciclo->id}/consumos-comida/importaciones", [])->assertForbidden();
    }

    public function test_endpoint_de_carga_valida_y_devuelve_previsualizacion(): void
    {
        [$empresa, $usuario, $ciclo] = $this->escenario();
        $usuario->update(['empresa_id' => $empresa->id]);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())];
        Auth::forgetGuards();

        $respuesta = $this->withHeaders($headers)->post("/api/ciclos-remunerativos/{$ciclo->id}/consumos-comida/importaciones", [
            'archivo' => $this->excel([['00123456', '2026-10-05', 'Almuerzo', '12.50', 'T-1']]),
            // Se ignora: la empresa siempre sale del ciclo autorizado.
            'empresa_id' => 999999,
        ], ['Accept' => 'application/json']);

        $respuesta->assertCreated();
        $this->assertSame('validada', $respuesta->json('data.importacion.estado'));
        $this->assertSame('12.50', $respuesta->json('data.previsualizacion.total_monto'));
        $this->assertSame($empresa->id, ConsumoComidaImportacion::firstOrFail()->empresa_id);
        Storage::disk('local')->assertExists(ConsumoComidaImportacion::firstOrFail()->archivo_ruta);

        $this->withHeaders($headers)->get('/api/consumos-comida/plantilla')->assertOk();
    }

    private function usuarioConRol(Empresa $empresa, Role $rol): array
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);
        $usuario->empresas()->updateExistingPivot($empresa->id, ['role_id' => $rol->id]);
        $usuario->refresh();
        // JWTAuth conserva el token ya leído entre peticiones del mismo test:
        // sin esto la siguiente petición seguiría autenticada como el
        // usuario anterior.
        JWTAuth::unsetToken();
        app('tymon.jwt')->unsetToken();
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)];
    }

    /** El cálculo exige asistencia procesada para todo el período (VerificarConsistenciaAsistenciaCiclo). */
    private function cubrirAsistencia(Empresa $empresa, string $desde, string $hasta): void
    {
        $filas = [];
        foreach (Colaborador::where('empresa_id', $empresa->id)->pluck('id') as $colaboradorId) {
            for ($fecha = Carbon::parse($desde); $fecha->lte(Carbon::parse($hasta)); $fecha->addDay()) {
                $filas[] = [
                    'empresa_id' => $empresa->id, 'colaborador_id' => $colaboradorId, 'fecha' => $fecha->toDateString(),
                    'tipo_dia' => 'laborable_presencial', 'estado' => 'presente', 'procesado_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($filas, 500) as $lote) {
            AsistenciaResultadoDiario::insert($lote);
        }
    }
}
