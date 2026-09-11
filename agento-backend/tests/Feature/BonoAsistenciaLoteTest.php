<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\BonoAsistenciaService;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Bono de Asistencia (política Livex, personal comercial) — se calcula y
 * aplica DENTRO del ciclo normal de planilla, SIEMPRE antes de pagarlo. Nunca
 * es Planilla Complementaria (ver BonoAsistenciaComplementariaTest para el
 * bono masivo sobre ciclos YA PAGADOS, un problema distinto que no se toca
 * aquí). Ejercita BonoAsistenciaService::generar()/exportarExcel()/
 * importarExcel()/aplicar() de punta a punta; el porcentaje/monto siempre se
 * verifica en el resultado persistido, nunca reimplementando
 * BonoAsistenciaCalculator en el test.
 */
class BonoAsistenciaLoteTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    /** @var array<int, string> rutas de xlsx temporales creadas por construirExcelLivex(), borradas en tearDown() */
    private array $archivosTemporales = [];

    protected function tearDown(): void
    {
        foreach ($this->archivosTemporales as $ruta) {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }

        parent::tearDown();
    }

    private function escenarioConLote(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $usuario = User::where('username', 'test.user')->firstOrFail();
        // COMISION: concepto tipo 'ingreso' que NO exige concepto_definicion_id
        // (a diferencia de BONIFICACION/BONO_NO_REMUNERATIVO) — evita depender
        // de ConceptoDefinicionPlame en un test que no necesita esa rama.
        $concepto = ConceptoRemuneracion::where('codigo', 'COMISION')->firstOrFail();

        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-09-05',
            'estado' => 'abierto', // NUNCA 'cerrado'/'pagado': este bono se calcula ANTES de pagar el ciclo.
        ]);

        $sinFaltas = $this->colaboradorConBono($empresa, '71000001', 150.00);

        $faltaJustificada = $this->colaboradorConBono($empresa, '71000002', 150.00);
        $this->marcarAsistencia($empresa, $faltaJustificada, '2026-08-05', 'falta_justificada');

        $faltaInjustificada = $this->colaboradorConBono($empresa, '71000003', 150.00);
        $this->marcarAsistencia($empresa, $faltaInjustificada, '2026-08-10', 'falta');

        // Bono base no configurado: debe quedar fuera del lote por completo.
        $sinBonoBase = $this->colaboradorConBono($empresa, '71000004', null);

        // Sin faltas (100% propuesto, igual que $sinFaltas), pero Livex lo
        // marcará como NO aprobado al reimportar — para probar que aplicar()
        // respeta esa decisión sin importar el porcentaje.
        $noAprobado = $this->colaboradorConBono($empresa, '71000005', 200.00);

        $service = app(BonoAsistenciaService::class);
        $lote = $service->generar($empresa, $ciclo, $concepto->id, null, 'Bono asistencia agosto', null, $usuario->id);

        return compact(
            'empresa', 'ciclo', 'usuario', 'concepto', 'service', 'lote',
            'sinFaltas', 'faltaJustificada', 'faltaInjustificada', 'sinBonoBase', 'noAprobado',
        );
    }

    /**
     * Igual que escenarioConLote(), pero ya con el Excel de Livex reimportado
     * (lote en estado 'revisado'): meta comercial cumplida y aprobado=Si para
     * $sinFaltas y $faltaJustificada; $faltaInjustificada aprobado=Si con su
     * única falta corregida a justificada; $noAprobado con aprobado=No.
     */
    private function escenarioRevisado(): array
    {
        $datos = $this->escenarioConLote();
        ['empresa' => $empresa, 'lote' => $lote, 'usuario' => $usuario, 'service' => $service] = $datos;

        $service->exportarExcel($empresa, $lote, $usuario->id);

        $archivo = $this->construirExcelLivex([
            ['documento' => '71000001', 'meta_comercial_cumplida' => 'Si', 'aprobado' => 'Si'],
            ['documento' => '71000002', 'meta_comercial_cumplida' => 'Si', 'aprobado' => 'Si'],
            ['documento' => '71000003', 'meta_comercial_cumplida' => 'No', 'aprobado' => 'Si', 'correccion' => 0],
            ['documento' => '71000005', 'meta_comercial_cumplida' => 'No', 'aprobado' => 'No'],
        ]);
        $service->importarExcel($empresa, $lote, $archivo, $usuario->id);
        $lote->refresh();

        return [...$datos, 'lote' => $lote];
    }

    private function colaboradorConBono(Empresa $empresa, string $numeroDocumento, ?float $bonoBase): Colaborador
    {
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2026-01-01',
            'numero_documento' => $numeroDocumento,
        ]);

        if ($bonoBase !== null) {
            // forceFill(): 'bono_asistencia_base' no está en el listado
            // #[Fillable] de ColaboradorRemuneracion (ver discrepancia
            // reportada) — no depender de que ese fix ya esté aplicado.
            $colaborador->remuneraciones()->first()->forceFill([
                'bono_asistencia_base' => $bonoBase,
                'vigencia_desde' => '2026-01-01',
            ])->save();
        }

        return $colaborador;
    }

    private function marcarAsistencia(Empresa $empresa, Colaborador $colaborador, string $fecha, string $estado): AsistenciaResultadoDiario
    {
        return AsistenciaResultadoDiario::create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha' => $fecha, 'tipo_dia' => 'laborable', 'estado' => $estado,
            'procesado_at' => now(),
        ]);
    }

    /**
     * Construye en disco un .xlsx real con los MISMOS encabezados (mismo slug
     * al leer) que BonoAsistenciaExcelExporter — ver
     * BonoAsistenciaXlsxReader::COLUMNA_* — para ejercitar importarExcel()
     * contra un archivo real, sin fixture estático.
     *
     * @param  array<int, array{documento: string, meta_comercial_cumplida?: string, aprobado?: string, correccion?: int|null, observacion?: string}>  $filas
     */
    private function construirExcelLivex(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([
            'Documento', 'Cumplio meta comercial (Si/No)', 'Aprobado (Si/No)',
            'Corregir dias falta injustificada (opcional)', 'Observacion',
        ], null, 'A1');

        foreach ($filas as $indice => $fila) {
            $numeroFila = $indice + 2;
            $hoja->setCellValueExplicit("A{$numeroFila}", (string) $fila['documento'], DataType::TYPE_STRING);
            $hoja->setCellValue("B{$numeroFila}", $fila['meta_comercial_cumplida'] ?? '');
            $hoja->setCellValue("C{$numeroFila}", $fila['aprobado'] ?? '');
            if (($fila['correccion'] ?? null) !== null) {
                $hoja->setCellValue("D{$numeroFila}", $fila['correccion']);
            }
            $hoja->setCellValue("E{$numeroFila}", $fila['observacion'] ?? '');
        }

        $ruta = storage_path('framework/testing/bono_asistencia_livex_'.uniqid('', true).'.xlsx');
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();
        $this->archivosTemporales[] = $ruta;

        return new UploadedFile($ruta, 'bono_asistencia_livex.xlsx', null, null, true);
    }

    public function test_generar_calcula_porcentaje_propuesto_segun_asistencia_y_excluye_sin_bono_base(): void
    {
        $datos = $this->escenarioConLote();
        ['lote' => $lote, 'sinFaltas' => $sinFaltas, 'faltaJustificada' => $faltaJustificada,
            'faltaInjustificada' => $faltaInjustificada, 'sinBonoBase' => $sinBonoBase] = $datos;

        $this->assertSame('borrador', $lote->estado);

        $detalles = $lote->detalles->keyBy('colaborador_id');
        // sinFaltas + faltaJustificada + faltaInjustificada + noAprobado — sinBonoBase queda fuera.
        $this->assertSame(4, $detalles->count());
        $this->assertFalse($detalles->has($sinBonoBase->id));

        $this->assertSame(100, $detalles[$sinFaltas->id]->porcentaje_propuesto);
        $this->assertSame('150.00', $detalles[$sinFaltas->id]->monto_propuesto);

        $this->assertSame(50, $detalles[$faltaJustificada->id]->porcentaje_propuesto);
        $this->assertSame('75.00', $detalles[$faltaJustificada->id]->monto_propuesto);
        $this->assertSame(1, $detalles[$faltaJustificada->id]->dias_falta_justificada);

        $this->assertSame(0, $detalles[$faltaInjustificada->id]->porcentaje_propuesto);
        $this->assertSame('0.00', $detalles[$faltaInjustificada->id]->monto_propuesto);
        $this->assertSame(1, $detalles[$faltaInjustificada->id]->dias_falta_injustificada);
    }

    public function test_exportar_excel_genera_contenido_no_vacio_y_marca_el_lote_exportado(): void
    {
        $datos = $this->escenarioConLote();
        ['empresa' => $empresa, 'lote' => $lote, 'usuario' => $usuario, 'service' => $service] = $datos;

        $contenido = $service->exportarExcel($empresa, $lote, $usuario->id);

        $this->assertNotEmpty($contenido);
        $this->assertSame('exportado', $lote->fresh()->estado);
        $this->assertSame($usuario->id, $lote->fresh()->exportado_por);
        $this->assertNotNull($lote->fresh()->archivo_exportado_nombre);
    }

    public function test_importar_excel_recalcula_porcentaje_final_con_meta_comercial_y_aprobacion_de_livex(): void
    {
        $datos = $this->escenarioRevisado();
        ['lote' => $lote, 'faltaJustificada' => $faltaJustificada, 'faltaInjustificada' => $faltaInjustificada,
            'sinFaltas' => $sinFaltas, 'noAprobado' => $noAprobado] = $datos;

        $this->assertSame('revisado', $lote->estado);

        $detalles = $lote->detalles()->get()->keyBy('colaborador_id');

        // 1 falta justificada + meta comercial cumplida => recupera el 100%.
        $this->assertSame(100, $detalles[$faltaJustificada->id]->porcentaje_final);
        $this->assertSame('150.00', $detalles[$faltaJustificada->id]->monto_final);
        $this->assertTrue($detalles[$faltaJustificada->id]->aprobado);
        $this->assertTrue($detalles[$faltaJustificada->id]->meta_comercial_cumplida);

        $this->assertSame(100, $detalles[$sinFaltas->id]->porcentaje_final);

        // Livex corrigió la única falta injustificada a justificada (0 días
        // injustificados) => vuelve a calificar para el 100%.
        $this->assertSame(0, $detalles[$faltaInjustificada->id]->dias_falta_injustificada_livex);
        $this->assertSame(100, $detalles[$faltaInjustificada->id]->porcentaje_final);

        $this->assertFalse($detalles[$noAprobado->id]->aprobado);
    }

    public function test_aplicar_crea_colaborador_concepto_periodo_solo_para_los_detalles_aprobados(): void
    {
        $datos = $this->escenarioRevisado();
        ['empresa' => $empresa, 'ciclo' => $ciclo, 'lote' => $lote, 'usuario' => $usuario, 'service' => $service,
            'faltaJustificada' => $faltaJustificada, 'noAprobado' => $noAprobado] = $datos;

        $lote = $service->aplicar($empresa, $lote, $usuario->id);

        $this->assertSame('aplicado', $lote->estado);

        $conceptoAprobado = ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)
            ->where('colaborador_id', $faltaJustificada->id)->first();
        $this->assertNotNull($conceptoAprobado);
        $this->assertSame('150.00', $conceptoAprobado->monto);

        $detalleAprobado = $lote->detalles()->where('colaborador_id', $faltaJustificada->id)->first();
        $this->assertSame($conceptoAprobado->id, $detalleAprobado->colaborador_concepto_periodo_id);

        $conceptoNoAprobado = ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)
            ->where('colaborador_id', $noAprobado->id)->first();
        $this->assertNull($conceptoNoAprobado);
    }

    public function test_aplicar_una_segunda_vez_sobre_el_mismo_lote_lanza_validation_exception_y_no_duplica(): void
    {
        $datos = $this->escenarioRevisado();
        ['empresa' => $empresa, 'ciclo' => $ciclo, 'lote' => $lote, 'usuario' => $usuario, 'service' => $service] = $datos;

        $lote = $service->aplicar($empresa, $lote, $usuario->id);
        $totalAntes = ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->count();

        try {
            $service->aplicar($empresa, $lote, $usuario->id);
            $this->fail('Se esperaba ValidationException al aplicar un lote ya aplicado.');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame($totalAntes, ColaboradorConceptoPeriodo::where('ciclo_id', $ciclo->id)->count());
    }

    public function test_aplicar_reclasifica_en_asistencia_la_falta_que_livex_corrigio_a_justificada(): void
    {
        $datos = $this->escenarioRevisado();
        ['empresa' => $empresa, 'lote' => $lote, 'usuario' => $usuario, 'service' => $service,
            'faltaInjustificada' => $faltaInjustificada] = $datos;

        $resultadoAntes = AsistenciaResultadoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->where('colaborador_id', $faltaInjustificada->id)->whereDate('fecha', '2026-08-10')->firstOrFail();
        $this->assertSame('falta', $resultadoAntes->estado);

        $service->aplicar($empresa, $lote, $usuario->id);

        $resultadoDespues = AsistenciaResultadoDiario::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->where('colaborador_id', $faltaInjustificada->id)->whereDate('fecha', '2026-08-10')->firstOrFail();
        $this->assertSame('falta_justificada', $resultadoDespues->estado);
    }
}
