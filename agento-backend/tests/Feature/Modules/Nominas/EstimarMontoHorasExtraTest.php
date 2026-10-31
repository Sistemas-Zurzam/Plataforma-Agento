<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Asistencia\Infrastructure\HorasExtraExcelExporter;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Application\EstimarMontoHorasExtra;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class EstimarMontoHorasExtraTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    public function test_locador_usa_las_tasas_configuradas_para_locacion(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: [
            'tipo_contrato' => 'locacion_servicios',
            'regimen_laboral' => 'Locacion de Servicios',
            'fecha_ingreso' => '2026-10-01',
        ]);
        $colaborador->remuneraciones()->update(['salario' => 2400, 'vigencia_desde' => '2026-10-01']);
        // Locación sin sobretasa: "cálculo normal" (tasa 1.00).
        foreach (['renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500, 'horas_extra_tasa_x25' => 1, 'horas_extra_tasa_x35' => 1] as $clave => $valor) {
            ParametroLaboralValor::create([
                'empresa_id' => $colaborador->empresa_id,
                'definicion_id' => ParametroLaboralDefinicion::where('clave', $clave)->value('id'),
                'regimen_laboral' => 'Locacion de Servicios',
                'vigencia_desde' => '2026-10-01',
                'valor' => $valor,
                'motivo' => 'Configuración de prueba',
            ]);
        }
        $horaExtra = $this->horaExtra($colaborador, '2026-10-13', '25', 120);

        $estimado = app(EstimarMontoHorasExtra::class)->paraIds($colaborador->empresa, [$horaExtra->id])[$horaExtra->id];

        // 2400 / 240 × 1.00 × 2 h
        $this->assertSame(20.0, $estimado['monto']);
        $this->assertSame(1.0, $estimado['tasa']);
    }

    public function test_dependiente_usa_la_sobretasa_del_regimen_y_aprobadas_si_existen(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: ['fecha_ingreso' => '2026-10-01']);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($colaborador->empresa);
        $colaborador->remuneraciones()->update(['salario' => 2400, 'vigencia_desde' => '2026-10-01']);
        $horaExtra = $this->horaExtra($colaborador, '2026-10-13', '25', 120, aprobados: 60);

        $estimado = app(EstimarMontoHorasExtra::class)->paraIds($colaborador->empresa, [$horaExtra->id])[$horaExtra->id];

        // 2400 / 240 × 1.25 × 1 h aprobada
        $this->assertSame(12.5, $estimado['monto']);
        $this->assertSame(60, $estimado['minutos']);
    }

    public function test_no_devuelve_horas_extra_de_otra_empresa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $propio = $this->crearColaborador(atributos: ['fecha_ingreso' => '2026-10-01']);
        $ajeno = $this->crearColaborador(Empresa::factory()->create(), ['fecha_ingreso' => '2026-10-01']);
        $horaExtraAjena = $this->horaExtra($ajeno, '2026-10-13', '25', 120);

        $this->assertSame([], app(EstimarMontoHorasExtra::class)->paraIds($propio->empresa, [$horaExtraAjena->id]));
    }

    public function test_excel_con_montos_agrega_la_columna_y_el_total_sin_rechazadas(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: ['fecha_ingreso' => '2026-10-01']);
        $aprobada = $this->horaExtra($colaborador, '2026-10-13', '25', 60, aprobados: 60);
        $rechazada = $this->horaExtra($colaborador, '2026-10-14', '25', 60);
        $rechazada->update(['estado' => AsistenciaHoraExtra::ESTADO_RECHAZADO]);
        $horasExtra = AsistenciaHoraExtra::whereIn('id', [$aprobada->id, $rechazada->id])->with('colaborador')->orderBy('id')->get();
        $montos = [$aprobada->id => ['monto' => 12.5], $rechazada->id => ['monto' => 12.5]];

        $contenido = HorasExtraExcelExporter::generar($colaborador->empresa, '2026-10-01', '2026-10-31', $horasExtra, $montos);
        $ruta = tempnam(sys_get_temp_dir(), 'he').'.xlsx';
        file_put_contents($ruta, $contenido);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame('Monto aprox. (S/)', $hoja->getCell('F4')->getValue());
        $this->assertSame('Estado', $hoja->getCell('G4')->getValue());
        $this->assertEquals(12.5, $hoja->getCell('F5')->getValue());
        $this->assertEquals(12.5, $hoja->getCell('F7')->getValue());
    }

    public function test_excel_sin_montos_conserva_las_columnas_de_asistencia(): void
    {
        $this->seed(DatabaseSeeder::class);
        $colaborador = $this->crearColaborador(atributos: ['fecha_ingreso' => '2026-10-01']);
        $this->horaExtra($colaborador, '2026-10-13', '25', 60);

        $contenido = HorasExtraExcelExporter::generar($colaborador->empresa, '2026-10-01', '2026-10-31', AsistenciaHoraExtra::with('colaborador')->get());
        $ruta = tempnam(sys_get_temp_dir(), 'he').'.xlsx';
        file_put_contents($ruta, $contenido);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        $this->assertSame('Estado', $hoja->getCell('F4')->getValue());
        $this->assertSame('Motivo', $hoja->getCell('G4')->getValue());
    }

    private function horaExtra(Colaborador $colaborador, string $fecha, string $tasa, int $observados, int $aprobados = 0): AsistenciaHoraExtra
    {
        return AsistenciaHoraExtra::create([
            'empresa_id' => $colaborador->empresa_id,
            'colaborador_id' => $colaborador->id,
            'fecha' => $fecha,
            'tasa' => $tasa,
            'minutos_observados' => $observados,
            'minutos_solicitados' => $observados,
            'minutos_aprobados' => $aprobados,
            'estado' => $aprobados > 0 ? 'aprobado' : 'pendiente',
        ]);
    }
}
