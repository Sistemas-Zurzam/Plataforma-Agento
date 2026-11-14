<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Permission;
use App\Modules\Configuracion\Models\Role;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDeposito;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/** CTS histórica cargada desde el Excel de RR.HH. (formato con título, encabezados en la fila 6, fórmulas y total). Datos ficticios. */
class CtsExcelHistoricoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->empresa = Empresa::factory()->create(['nombre_comercial' => 'Empresa Prueba']);
        $user = User::factory()->create(['empresa_id' => $this->empresa->id]);
        $user->empresas()->syncWithoutDetaching([$this->empresa->id => ['role_id' => Role::administrador()->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user->fresh())]);
    }

    /** @param  list<array{0: string|int, 1: float, 2: int, 3: float, 4: float, 5: float}>  $filas  documento, sueldo, días, gratificación, HE, comisiones */
    private function excel(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('C3', 'DEPOSITO DE CTS NOVIEMBRE 2026');
        $hoja->fromArray(['EMPRESA', 'Nombre Completo', 'No de cuenta CTS', 'Fecha de Nacimiento', 'Documento', 'Fecha Ingreso', 'Periodo final del computo',
            'Sueldo Base', 'Dias computables', 'Gratificación Julio 26', '1/6 Gratif.', 'promedio de hra extra', 'Promedio Comisiones',
            'Base computable CTS', 'CTS 100%', 'CTS A DEPOSITAR 50%(MYPE)'], null, 'A6');
        $n = 7;
        foreach ($filas as [$documento, $sueldo, $dias, $grati, $he, $comisiones]) {
            $hoja->fromArray(['EMPRESA PRUEBA', 'PERSONA FICTICIA', '', '', $documento, '', '2026-10-31', $sueldo, $dias, $grati], null, "A{$n}");
            $hoja->setCellValue("K{$n}", "=J{$n}/6");
            $hoja->setCellValue("L{$n}", $he);
            $hoja->setCellValue("M{$n}", $comisiones);
            $hoja->setCellValue("N{$n}", "=H{$n}+K{$n}+L{$n}+M{$n}");
            $hoja->setCellValue("O{$n}", "=N{$n}/360*I{$n}");
            $hoja->setCellValue("P{$n}", "=O{$n}/2");
            $n++;
        }
        $hoja->setCellValue("P{$n}", '=SUM(P7:P'.($n - 1).')'); // fila de total, sin documento

        $ruta = tempnam(sys_get_temp_dir(), 'cts').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'CTS NOVIEMBRE 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function datos(UploadedFile $archivo): array
    {
        return ['archivo' => $archivo, 'tipo' => 'cts_noviembre', 'anio' => 2026];
    }

    public function test_previsualiza_e_importa_la_cts_del_excel_como_version_vigente(): void
    {
        // El DNI con 0 inicial llega como número desde Excel (pierde el 0) y aun así se reconoce.
        $uno = $this->crearColaborador($this->empresa, ['numero_documento' => '01234567', 'cts_cuenta' => '00110000000000000001']);
        $dos = $this->crearColaborador($this->empresa, ['numero_documento' => '87654321', 'cts_cuenta' => '00110000000000000002']);
        $archivo = fn () => $this->excel([[1234567, 1200, 180, 600, 0, 0], ['87654321', 1400, 120, 0, 90, 30]]);

        $vista = $this->post('/api/beneficios-sociales/cts/excel/previsualizar', $this->datos($archivo()))->assertOk()->json('data');
        $this->assertSame(0, $vista['con_errores']);
        $this->assertCount(2, $vista['filas']);
        // (1,200 + 100) / 360 x 180 x 50% = 325.00 ; (1,400 + 90 + 30) / 360 x 120 x 50% = 253.33
        $this->assertEquals([325, 253.33], array_column($vista['filas'], 'monto'));
        $this->assertSame([0.5, 0.5], array_column($vista['filas'], 'tasa'));
        $this->assertSame(578.33, $vista['total']);
        $this->assertSame($uno->id, $vista['filas'][0]['colaborador_id']);
        $this->assertSame(0, BeneficioSocial::count(), 'La vista previa no guarda nada.');

        $resumen = $this->post('/api/beneficios-sociales/cts/excel', $this->datos($archivo()))->assertOk()->json();
        $this->assertTrue($resumen['calculado']);
        $this->assertSame('excel', $resumen['origen']);
        $this->assertSame('calculado', $resumen['estado']);
        $this->assertSame(578.33, $resumen['total_aprobable']);
        $fila = collect($resumen['colaboradores'])->firstWhere('colaborador_id', $dos->id);
        $this->assertSame(253.33, $fila['importe_aprobable']);
        $this->assertSame(['SUELDO_BASICO' => 1400, 'PROMEDIO_HORAS_EXTRA' => 90, 'PROMEDIO_COMISIONES' => 30], $fila['conceptos']);
        $this->assertSame(120, $fila['dias_computables']);

        // No se recalcula por encima de la CTS del Excel.
        $this->postJson('/api/beneficios-sociales/calcular', ['tipo' => 'cts_noviembre', 'anio' => 2026])->assertStatus(422);

        // Sirve para el TXT y la boleta de CTS.
        $beneficio = BeneficioSocial::where('es_version_vigente', true)->firstOrFail();
        $ids = $beneficio->detalles()->pluck('id')->all();
        $txt = $this->postJson("/api/beneficios-sociales/{$beneficio->id}/cts/txt-bbva/validacion", ['detalle_ids' => $ids])->json('data');
        $this->assertCount(2, $txt['incluidos']);
        $this->assertSame(578.33, $txt['total']);
        $this->get("/api/beneficios-sociales/detalles/{$ids[0]}/boleta-cts")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_guarda_las_filas_validas_y_al_recargar_completa_sin_tocar_lo_depositado(): void
    {
        $c = $this->crearColaborador($this->empresa, ['numero_documento' => '87654321']);
        // (1,440 / 360) x 180 x 50% = 360.00 ; (1,080 / 360) x 120 x 50% = 180.00
        $archivo = fn (float $sueldoUno = 1440) => $this->excel([['87654321', $sueldoUno, 180, 0, 0, 0], ['11112222', 1080, 120, 0, 0, 0]]);

        $vista = $this->post('/api/beneficios-sociales/cts/excel/previsualizar', $this->datos($archivo()))->assertOk()->json('data');
        $this->assertSame(1, $vista['con_errores']);
        $this->assertSame(1, $vista['validas']);
        $this->assertEquals(360, $vista['total_validas']);
        $this->assertStringContainsString('No hay un colaborador', $vista['filas'][1]['errores'][0]);

        // Primera carga: se guarda solo la fila válida.
        $this->post('/api/beneficios-sociales/cts/excel', $this->datos($archivo()))->assertOk();
        $cts = BeneficioSocial::where('es_version_vigente', true)->firstOrFail();
        $this->assertSame(1, $cts->detalles()->count());
        $d = $cts->detalles()->firstOrFail();
        BeneficioSocialDeposito::create(['beneficio_social_detalle_id' => $d->id, 'fecha_deposito' => '2026-11-10', 'importe' => 360, 'entidad' => 'BBVA', 'referencia' => 'X-1', 'idempotencia' => str_repeat('b', 64)]);

        // Se registra al colaborador que faltaba y se vuelve a cargar (con un cambio en la fila ya depositada).
        $this->crearColaborador($this->empresa, ['numero_documento' => '11112222']);
        $vista = $this->post('/api/beneficios-sociales/cts/excel/previsualizar', $this->datos($archivo(2000)))->assertOk()->json('data');
        $this->assertSame('completar', $vista['vigente']['modo']);
        $this->assertFalse($vista['vigente']['bloqueada']);
        $this->assertStringContainsString('Ya tiene depósito', implode(' ', $vista['filas'][0]['advertencias']));

        $this->post('/api/beneficios-sociales/cts/excel', $this->datos($archivo(2000)))->assertOk();
        $this->assertSame(1, BeneficioSocial::count(), 'Completa la misma versión, no crea otra.');
        $cts->refresh();
        $this->assertSame(2, $cts->detalles()->count());
        $this->assertEquals(360, $d->fresh()->neta, 'Lo ya depositado no cambia.');
        $this->assertEquals(540, $cts->total_neto);
        $this->assertSame($c->id, $d->colaborador_id);
    }

    public function test_si_ninguna_fila_es_valida_no_guarda(): void
    {
        $this->post('/api/beneficios-sociales/cts/excel', $this->datos($this->excel([['99999999', 1400, 180, 0, 0, 0]])), ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, BeneficioSocial::count());
    }

    public function test_no_reemplaza_una_cts_con_depositos(): void
    {
        $c = $this->crearColaborador($this->empresa, ['numero_documento' => '87654321']);
        $cts = BeneficioSocial::create(['empresa_id' => $this->empresa->id, 'tipo' => 'cts_noviembre', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
            'total_colaboradores' => 1, 'total_bruto' => 100, 'total_neto' => 100, 'estado' => 'calculado', 'calculado_at' => now()]);
        $d = BeneficioSocialDetalle::create(['beneficio_social_id' => $cts->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 1000, 'meses' => 6,
            'bruta' => 100, 'bonificacion_extraordinaria' => 0, 'neta' => 100, 'detalle_calculo' => ['estado' => 'calculado']]);
        BeneficioSocialDeposito::create(['beneficio_social_detalle_id' => $d->id, 'fecha_deposito' => '2026-11-10', 'importe' => 100, 'entidad' => 'BBVA', 'referencia' => 'X-1', 'idempotencia' => str_repeat('a', 64)]);

        $this->post('/api/beneficios-sociales/cts/excel', $this->datos($this->excel([['87654321', 1400, 180, 0, 0, 0]])), ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertTrue($cts->fresh()->es_version_vigente);
    }

    public function test_solo_un_administrador_carga_la_cts_desde_excel(): void
    {
        $this->crearColaborador($this->empresa, ['numero_documento' => '87654321']);
        $rol = Role::create(['clave' => 'nomina_calculo_cts', 'nombre' => 'Nómina cálculo']);
        $rol->permissions()->sync(Permission::whereIn('clave', ['nominas.ver', 'nominas.calcular'])->pluck('id'));
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        $usuario->empresas()->syncWithoutDetaching([$this->empresa->id => ['role_id' => $rol->id]]);
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario->fresh())]);
        $archivo = fn () => $this->excel([['87654321', 1400, 180, 0, 0, 0]]);

        $this->post('/api/beneficios-sociales/cts/excel/previsualizar', $this->datos($archivo()), ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/api/beneficios-sociales/cts/excel', $this->datos($archivo()), ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, BeneficioSocial::count());
    }

    public function test_rechaza_un_excel_sin_las_columnas_de_la_cts(): void
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->fromArray([['Documento', 'Nombre'], ['87654321', 'X']]);
        $ruta = tempnam(sys_get_temp_dir(), 'cts').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $this->post('/api/beneficios-sociales/cts/excel/previsualizar', $this->datos(new UploadedFile($ruta, 'x.xlsx', null, null, true)), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.archivo.0', fn ($m) => str_contains($m, 'Faltan columnas'));
    }
}
