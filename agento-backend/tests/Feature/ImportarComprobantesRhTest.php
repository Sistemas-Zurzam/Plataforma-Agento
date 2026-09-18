<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Services\ImportarComprobantesRhService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class ImportarComprobantesRhTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_importa_varios_recibos_del_mismo_documento_en_la_empresa_y_ciclo(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'numero_documento' => '10489126384', 'tipo_documento' => 'ruc',
            'tipo_trabajador' => 'locador', 'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios', 'categoria_trabajador' => null,
        ]);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026', 'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 1500, 'dias_pagados' => 0,
            'total_ingresos' => 1500, 'total_egresos' => 0, 'total_aportaciones' => 0, 'neto_a_pagar' => 1500,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        $libro = new Spreadsheet(); $hoja = $libro->getActiveSheet();
        $hoja->fromArray([
            ['3/8/2026', 'RH', 'E001-51', 'NO ANULADO', 'RUC', '10489126384', 'LOCADOR', 'A', 'NO', 'SOLES', 183.33, 0, 183.33],
            ['26/8/2026', 'RH', 'E001-53', 'NO ANULADO', 'RUC', '10489126384', 'LOCADOR', 'A', 'NO', 'SOLES', 366.67, 0, 366.67],
            ['26/8/2026', 'RH', 'E001-99', 'NO ANULADO', 'RUC', '10999999999', 'SIN BOLETA', 'A', 'NO', 'SOLES', 100, 0, 100],
        ], null, 'A6');
        $ruta = tempnam(sys_get_temp_dir(), 'rh_').'.xlsx'; (new Xlsx($libro))->save($ruta); $libro->disconnectWorksheets();
        try {
            $resultado = app(ImportarComprobantesRhService::class)->importar($empresa, $ciclo, $ruta, '2026-08-31', User::firstOrFail()->id);
            $this->assertSame(2, $resultado['validos']);
            $this->assertSame(2, $resultado['importados']);
            $this->assertSame(1, $resultado['errores']);
            $this->assertCount(2, $boleta->comprobantesRh()->get());
            $this->assertEquals(550, $boleta->comprobantesRh()->sum('monto_total_servicio'));

            $segundaRevision = app(ImportarComprobantesRhService::class)->revisar($empresa, $ciclo, $ruta, '2026-08-31');
            $this->assertFalse($segundaRevision['listo']);
            $this->assertSame(0, $segundaRevision['resumen']['validos']);
            $this->assertSame(2, $segundaRevision['resumen']['omitidos']);
            $this->assertSame(1, $segundaRevision['resumen']['errores']);
        } finally { @unlink($ruta); }
    }

    public function test_encuentra_por_dni_al_colaborador_cuando_el_excel_trae_su_ruc_personal(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'numero_documento' => '48912638', 'tipo_documento' => 'dni',
            'tipo_trabajador' => 'locador', 'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios', 'categoria_trabajador' => null,
        ]);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026', 'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 1500, 'dias_pagados' => 0,
            'total_ingresos' => 1500, 'total_egresos' => 0, 'total_aportaciones' => 0, 'neto_a_pagar' => 1500,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([
            ['3/8/2026', 'RH', 'E001-51', 'NO ANULADO', 'RUC', '10489126384', 'LOCADOR', 'A', 'NO', 'SOLES', 183.33, 0, 183.33],
        ], null, 'A6');
        $ruta = tempnam(sys_get_temp_dir(), 'rh_').'.xlsx';
        (new Xlsx($libro))->save($ruta); $libro->disconnectWorksheets();

        try {
            $revision = app(ImportarComprobantesRhService::class)->revisar($empresa, $ciclo, $ruta, '2026-08-31');
            $this->assertTrue($revision['listo']);
            $this->assertSame('48912638', $revision['filas'][0]['documento_match']);
            $this->assertSame('dni_desde_ruc', $revision['filas'][0]['tipo_match']);

            app(ImportarComprobantesRhService::class)->importar($empresa, $ciclo, $ruta, '2026-08-31', User::firstOrFail()->id);
            $this->assertDatabaseHas('boleta_comprobantes_rh', [
                'boleta_id' => $boleta->id, 'serie' => 'E001', 'numero' => '51', 'monto_total_servicio' => 183.33,
            ]);
        } finally { @unlink($ruta); }
    }

    public function test_interpreta_una_fecha_nativa_de_excel_sin_invertir_dia_y_mes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        $colaborador = $this->crearColaborador($empresa, [
            'numero_documento' => '48912638', 'tipo_documento' => 'dni',
            'tipo_trabajador' => 'locador', 'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios', 'categoria_trabajador' => null,
        ]);
        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Agosto 2026', 'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);
        Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'Locacion de Servicios', 'sueldo_basico_snapshot' => 1500, 'dias_pagados' => 0,
            'total_ingresos' => 1500, 'total_egresos' => 0, 'total_aportaciones' => 0, 'neto_a_pagar' => 1500,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
            'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([[Date::PHPToExcel(new \DateTimeImmutable('2026-08-03')), 'RH', 'E001-51', 'NO ANULADO', 'RUC', '10489126384', 'LOCADOR', 'A', 'NO', 'SOLES', 183.33, 0, 183.33]], null, 'A6');
        $hoja->getStyle('A6')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $ruta = tempnam(sys_get_temp_dir(), 'rh_').'.xlsx';
        (new Xlsx($libro))->save($ruta); $libro->disconnectWorksheets();

        try {
            $revision = app(ImportarComprobantesRhService::class)->revisar($empresa, $ciclo, $ruta, '2026-08-31');
            $this->assertTrue($revision['listo']);
            $this->assertSame('2026-08-03', $revision['filas'][0]['fecha_emision']);
        } finally { @unlink($ruta); }
    }
}
