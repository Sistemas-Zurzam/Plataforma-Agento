<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\ExcelBonoAsistenciaService;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ExcelBonoAsistenciaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private array $temporales = [];
    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) if (is_file($ruta)) unlink($ruta);
        parent::tearDown();
    }
    private function guardar(Spreadsheet $libro): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'bono_test_'); $this->temporales[] = $ruta;
        (new Xlsx($libro))->save($ruta);
        return $ruta;
    }
    private function escenario(bool $suspension = false): array
    {
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        ParametrosVigentesResolver::limpiarCache();
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $banco = Banco::firstOrCreate(['codigo' => 'bcp'], ['nombre' => 'BCP', 'activo' => true]);
        $persona = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01', 'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios', 'tiene_suspension_renta_4ta' => $suspension,
            'numero_documento' => '01234567', 'banco_id' => $banco->id, 'numero_cuenta' => '19123456789012', 'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN']);
        $ciclo = CicloRemunerativo::create(['empresa_id' => $empresa->id, 'nombre' => 'Septiembre 2026',
            'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-30', 'fecha_corte_asistencia' => '2026-09-30', 'fecha_pago' => '2026-09-30', 'estado' => 'pagado']);
        $boleta = Boleta::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $persona->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 5000, 'dias_pagados' => 30,
            'total_ingresos' => 5000, 'total_egresos' => $suspension ? 0 : 400, 'total_aportaciones' => 0,
            'neto_a_pagar' => $suspension ? 5000 : 4600, 'estado' => 'pagada', 'es_version_vigente' => true,
            'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now()]);
        foreach ([['HONORARIO_BRUTO', 'ingreso', 5000], ['RETENCION_RENTA_4TA', 'egreso', $suspension ? 0 : 400]] as [$codigo, $tipo, $monto]) {
            $boleta->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                'tipo' => $tipo, 'monto' => $monto, 'base_utilizada' => 5000, 'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => false]);
        }
        for ($dia = 1; $dia <= 30; $dia++) {
            $fecha = sprintf('2026-09-%02d', $dia);
            AsistenciaResultadoDiario::create(['empresa_id' => $empresa->id, 'colaborador_id' => $persona->id, 'fecha' => $fecha,
                'tipo_dia' => $dia <= 26 ? 'laborable_presencial' : 'descanso', 'estado' => $dia <= 26 ? 'presente' : 'descanso',
                'entrada_at' => $dia <= 26 ? $fecha.' 09:00:00' : null, 'salida_at' => $dia <= 26 ? $fecha.' 18:00:00' : null,
                'minutos_trabajados' => $dia <= 26 ? 480 : 0, 'procesado_at' => now()]);
        }
        $service = app(ExcelBonoAsistenciaService::class);
        $libro = $service->exportar($empresa, '2026-09', 150);
        $fila = 2;
        while ((string) $libro->getActiveSheet()->getCell('A'.$fila)->getValue() !== (string) $persona->id) $fila++;
        foreach (['V' => 'Aprobado', 'W' => 'No', 'X' => '150.00', 'Y' => 'Gerencia LIVEX', 'Z' => '2026-10-05', 'AA' => 'RRHH verificó las marcaciones y autorizaciones de los días observados'] as $col => $v) $libro->getActiveSheet()->setCellValue($col.$fila, $v);
        return [$empresa, $ciclo, $boleta, $usuario, $service, $libro, $fila];
    }

    public function test_excel_honorarios_valida_genera_txt_y_bloquea_reimportacion(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service, $libro, $fila] = $this->escenario();
        $this->assertSame('01234567', $libro->getActiveSheet()->getCell('E'.$fila)->getValue());
        $archivo = $this->guardar($libro);
        $revision = $service->validar($empresa, $ciclo, $archivo);
        $this->assertTrue($revision['listo'], json_encode($revision['errores']));
        $this->assertSame(150.0, $revision['total_bruto']);
        $this->assertDatabaseCount('planillas_complementarias', 0);
        $item = $service->generar($empresa, $ciclo, $archivo, null, null, 'Bonos aprobados', $usuario->id);
        $this->assertSame('138.00', $item->detalles->first()->diferencia_neta);
        $this->assertSame('4600.00', $boleta->fresh()->neto_a_pagar);
        $this->assertSame('Gerencia LIVEX', $item->detalles->first()->calculo_snapshot['bono_asistencia_gerencia']['responsable']);
        $planillas = app(PlanillaComplementariaService::class);
        $planillas->aprobar($empresa, $item, $usuario->id);
        $txt = $planillas->exportarBcp($empresa, $item, new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']), '2026-10-05', '4');
        $this->assertStringContainsString('00000000000138.00', $txt);
        $this->assertFalse($service->validar($empresa, $ciclo, $archivo)['listo']);
        $this->expectException(ValidationException::class);
        $service->generar($empresa, $ciclo, $archivo, null, null, 'Duplicado', $usuario->id);
    }

    public function test_archivo_mixto_separa_categorias_y_no_modifica_las_boletas(): void
    {
        [$empresa, $ciclo, $rh, $usuario, $service] = $this->escenario();
        $dependiente = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01', 'numero_documento' => '87654321']);
        $boleta = $rh->replicate();
        $boleta->colaborador_id = $dependiente->id;
        $boleta->regimen_laboral_snapshot = 'General';
        $boleta->sueldo_basico_snapshot = 1400;
        $boleta->total_ingresos = 1400; $boleta->total_egresos = 182; $boleta->total_aportaciones = 126; $boleta->neto_a_pagar = 1218;
        $boleta->save();
        foreach ([['SUELDO_BASICO', 'ingreso', 1400], ['ONP', 'egreso', 182], ['ESSALUD', 'aportacion', 126]] as [$codigo, $tipo, $monto]) {
            $boleta->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                'tipo' => $tipo, 'monto' => $monto, 'base_utilizada' => 1400, 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true]);
        }
        foreach (AsistenciaResultadoDiario::where('colaborador_id', $rh->colaborador_id)->get() as $resultado) {
            $copia = $resultado->replicate(); $copia->colaborador_id = $dependiente->id; $copia->save();
        }
        $libro = $service->exportar($empresa, '2026-09', 150);
        for ($fila = 2; $fila <= $libro->getActiveSheet()->getHighestDataRow(); $fila++) {
            if (! in_array((int) $libro->getActiveSheet()->getCell('A'.$fila)->getValue(), [$rh->colaborador_id, $dependiente->id], true)) continue;
            foreach (['V' => 'Aprobado', 'W' => 'No', 'X' => '150', 'Y' => 'Gerencia', 'Z' => '2026-10-05', 'AA' => 'RRHH validó las incidencias'] as $col => $valor) $libro->getActiveSheet()->setCellValue($col.$fila, $valor);
        }
        $concepto = ConceptoRemuneracion::where('codigo', 'BONO_NO_REMUNERATIVO')->firstOrFail();
        $definicion = \App\Modules\Nominas\Models\ConceptoDefinicionPlame::create(['concepto_remuneracion_id' => $concepto->id,
            'nombre' => 'Bono prueba', 'codigo_plame' => '0902', 'activo' => true, 'creado_por' => $usuario->id]);
        $archivo = $this->guardar($libro);
        $item = $service->generar($empresa, $ciclo, $archivo, $concepto->id, $definicion->id, 'Aprobación mixta', $usuario->id);
        $this->assertCount(2, $item->detalles);
        $this->assertSame('138.00', $item->detalles->firstWhere('colaborador_id', $rh->colaborador_id)->diferencia_neta);
        $this->assertSame('150.00', $item->detalles->firstWhere('colaborador_id', $dependiente->id)->diferencia_neta);
        $this->assertSame('1218.00', $boleta->fresh()->neto_a_pagar);
        $planillas = app(PlanillaComplementariaService::class);
        $planillas->aprobar($empresa, $item, $usuario->id);
        $this->assertCount(1, $planillas->boletasDePago($empresa, $item, '4'));
        $this->assertCount(1, $planillas->boletasDePago($empresa, $item, '5'));
    }

    public function test_http_valida_y_rechaza_otro_mes_o_filas_duplicadas(): void
    {
        [$empresa, $ciclo, , $usuario, $service, $libro, $fila] = $this->escenario();
        $archivo = $this->guardar($libro);
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($usuario);
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson("/api/ciclos-remunerativos/{$ciclo->id}/complementarias/bono-asistencia/excel", [
            'archivo' => new \Illuminate\Http\UploadedFile($archivo, 'Bono.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])->assertOk()->assertJsonPath('data.listo', true);
        $otro = $ciclo->replicate(); $otro->fecha_inicio = '2026-08-01'; $otro->fecha_fin = '2026-08-31'; $otro->save();
        $this->assertFalse($service->validar($empresa, $otro, $archivo)['listo']);
        $hoja = $libro->getActiveSheet();
        $copia = $hoja->getHighestDataRow() + 1;
        for ($col = 1; $col <= 28; $col++) $hoja->setCellValueExplicit([$col, $copia], $hoja->getCell([$col, $fila])->getValue(), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $revision = $service->validar($empresa, $ciclo, $this->guardar($libro));
        $this->assertFalse($revision['listo']);
        $this->assertStringContainsString('repetido', $revision['errores'][0]['mensaje']);
        $this->assertDatabaseCount('planillas_complementarias', 0);
    }

    public function test_recuperacion_exige_meta_y_exclusiones_no_se_pueden_aprobar(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario(true);
        $dia = AsistenciaResultadoDiario::where('colaborador_id', $boleta->colaborador_id)->whereDate('fecha', '2026-09-26')->firstOrFail();
        $dia->update(['estado' => 'permiso', 'entrada_at' => null, 'salida_at' => null, 'minutos_trabajados' => 0]);
        \App\Modules\Asistencia\Models\AsistenciaPermiso::create(['empresa_id' => $empresa->id, 'colaborador_id' => $boleta->colaborador_id,
            'tipo' => 'personal', 'fecha_inicio' => '2026-09-26', 'fecha_fin' => '2026-09-26', 'motivo' => 'Sustento autorizado',
            'estado' => 'aprobado', 'registrado_por' => $usuario->id]);
        $libro = $service->exportar($empresa, '2026-09', 150);
        $hoja = $libro->getActiveSheet();
        $fila = 2; while ((int) $hoja->getCell('A'.$fila)->getValue() !== $boleta->colaborador_id) $fila++;
        foreach (['V' => 'Aprobado', 'W' => 'No', 'X' => '150', 'Y' => 'Gerencia', 'Z' => '2026-10-05', 'AA' => 'Se validó el sustento de RRHH'] as $col => $valor) $hoja->setCellValue($col.$fila, $valor);
        $this->assertFalse($service->validar($empresa, $ciclo, $this->guardar($libro))['listo']);
        $hoja->setCellValue('W'.$fila, 'Si');
        $this->assertTrue($service->validar($empresa, $ciclo, $this->guardar($libro))['listo']);
        AsistenciaResultadoDiario::where('colaborador_id', $boleta->colaborador_id)->whereDay('fecha', '<=', 3)->update(['minutos_tardanza' => 5]);
        $nuevo = $service->exportar($empresa, '2026-09', 150);
        foreach (['V' => 'Aprobado', 'W' => 'Si', 'X' => '150', 'Y' => 'Gerencia', 'Z' => '2026-10-05', 'AA' => 'Solicitud de Gerencia'] as $col => $valor) $nuevo->getActiveSheet()->setCellValue($col.$fila, $valor);
        $validacion = $service->validar($empresa, $ciclo, $this->guardar($nuevo));
        $this->assertFalse($validacion['listo']);
        $this->assertStringContainsString('No cumple', $validacion['errores'][0]['mensaje']);
        $this->assertDatabaseCount('planillas_complementarias', 0);
    }

    public function test_suspension_conserva_bono_completo_y_rechaza_montos_alteraciones_y_datos_obsoletos(): void
    {
        [$empresa, $ciclo, , $usuario, $service, $libro, $fila] = $this->escenario(true);
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('X'.$fila, '151');
        $this->assertFalse($service->validar($empresa, $ciclo, $this->guardar($libro))['listo']);
        $hoja->setCellValue('X'.$fila, '=150');
        $this->assertFalse($service->validar($empresa, $ciclo, $this->guardar($libro))['listo']);
        $hoja->setCellValue('X'.$fila, '150');
        $hoja->setCellValue('F'.$fila, '30');
        $this->assertFalse($service->validar($empresa, $ciclo, $this->guardar($libro))['listo']);
        $hoja->setCellValue('F'.$fila, '26');
        $archivo = $this->guardar($libro);
        $item = $service->generar($empresa, $ciclo, $archivo, null, null, 'Bonos con suspensión', $usuario->id);
        $this->assertSame('150.00', $item->detalles->first()->diferencia_neta);
        $this->assertEmpty($item->detalles->first()->calculo_snapshot['aportaciones']);
        AsistenciaResultadoDiario::query()->first()->update(['minutos_tardanza' => 10]);
        $resultado = $service->validar($empresa, $ciclo, $archivo);
        $this->assertStringContainsString('cambiaron', $resultado['errores'][0]['mensaje']);
    }
}
