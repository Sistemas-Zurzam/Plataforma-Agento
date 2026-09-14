<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export\ReporteEjecutivoRemuneracionesExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class ReporteEjecutivoRemuneracionesExcelExporterTest extends TestCase
{
    public function test_genera_detalle_y_resumen_ejecutivo_para_un_colaborador_de_planilla(): void
    {
        $empresa = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Demo']);
        $ciclo = (new CicloRemunerativo)->forceFill(['nombre' => 'Agosto 2026']);
        $ciclo->setRelation('empresa', $empresa);

        $colaborador = (new Colaborador)->forceFill([
            'numero_documento' => '70826733',
            'nombres' => 'Sixto Edu',
            'apellidos' => 'Velarde Miranda',
        ]);

        $boleta = (new Boleta)->forceFill([
            'regimen_laboral_snapshot' => 'General',
            'total_ingresos' => '1400.00',
            'total_egresos' => '159.18',
            'neto_a_pagar' => '1240.82',
            'estado' => 'pagada',
        ]);
        $boleta->setRelation('colaborador', $colaborador);
        $boleta->setRelation('conceptos', new Collection([
            $this->concepto('SUELDO_BASICO', 'ingreso', '1400.00'),
            $this->concepto('AFP_APORTE_OBLIGATORIO', 'egreso', '140.00', baseUtilizada: '1400.00'),
            $this->concepto('AFP_PRIMA_SEGURO', 'egreso', '19.18'),
            $this->concepto('ESSALUD', 'aportacion', '126.00'),
        ]));

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generar($ciclo, new Collection([$boleta]));
        $ruta = dirname(__DIR__, 2).'/storage/framework/testing/reporte_ejecutivo_'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $libro = IOFactory::load($ruta);

            $detalle = $libro->getSheetByName('Detalle_Planilla');
            $this->assertNotNull($detalle);
            $this->assertSame('70826733', $detalle->getCell('C2')->getValue());
            $this->assertSame('Sixto Edu Velarde Miranda', $detalle->getCell('D2')->getValue());
            $this->assertSame('Planilla', $detalle->getCell('E2')->getValue());
            $this->assertSame(1400.0, $detalle->getCell('F2')->getValue());
            $this->assertSame(0.0, $detalle->getCell('G2')->getValue());
            $this->assertSame(1400.0, $detalle->getCell('H2')->getValue());
            $this->assertSame(140.0, $detalle->getCell('I2')->getValue());
            $this->assertSame(19.18, $detalle->getCell('J2')->getValue());
            $this->assertSame(159.18, $detalle->getCell('L2')->getValue());
            $this->assertSame(1240.82, $detalle->getCell('M2')->getValue());
            $this->assertSame(126.0, $detalle->getCell('N2')->getValue());
            $this->assertSame(1526.0, $detalle->getCell('O2')->getValue());
            $this->assertSame('Pagado', $detalle->getCell('P2')->getValue());

            $ejecutivo = $libro->getSheetByName('Reporte_Ejecutivo');
            $this->assertNotNull($ejecutivo);
            $this->assertSame('REPORTE EJECUTIVO DE REMUNERACIONES', $ejecutivo->getCell('A1')->getValue());
            $this->assertSame('Empresa Demo', $ejecutivo->getCell('B3')->getValue());
            $this->assertSame(1, $ejecutivo->getCell('A9')->getValue());
            $this->assertSame(1400.0, $ejecutivo->getCell('B9')->getValue());
            $this->assertSame(159.18, $ejecutivo->getCell('D9')->getValue());
            $this->assertSame(1240.82, $ejecutivo->getCell('E9')->getValue());
            $this->assertSame(126.0, $ejecutivo->getCell('F9')->getValue());
            $this->assertSame(1526.0, $ejecutivo->getCell('G9')->getValue());
        } finally {
            unlink($ruta);
        }
    }

    private function concepto(string $codigo, string $tipo, string $monto, ?string $baseUtilizada = null): BoletaConcepto
    {
        $concepto = (new BoletaConcepto)->forceFill([
            'tipo' => $tipo,
            'monto' => $monto,
            'base_utilizada' => $baseUtilizada,
        ]);
        $concepto->setRelation('concepto', (new ConceptoRemuneracion)->forceFill(['codigo' => $codigo]));

        return $concepto;
    }
}
