<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export\ReporteEjecutivoRemuneracionesExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class ReporteEjecutivoRemuneracionesExcelExporterTest extends TestCase
{
    public function test_agrupa_por_empresa_con_subtotales_y_total_general(): void
    {
        $empresaUno = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Uno']);
        $empresaDos = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Dos']);

        $boletas = new Collection([
            $this->boleta($empresaUno, '10000001', 'Ana', 'Perez', sueldo: 1000, aporte: 100, essalud: 90, neto: 900),
            $this->boleta($empresaUno, '10000002', 'Bruno', 'Gomez', sueldo: 2000, aporte: 200, essalud: 180, neto: 1800),
            $this->boleta($empresaDos, '20000001', 'Carla', 'Ruiz', sueldo: 1500, aporte: 150, essalud: 135, neto: 1350),
        ]);

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generar('Julio 2026', $boletas);
        $ruta = dirname(__DIR__, 2).'/storage/framework/testing/reporte_ejecutivo_'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $libro = IOFactory::load($ruta);
            $detalle = $libro->getSheetByName('Detalle_Planilla');
            $this->assertNotNull($detalle);

            // Fila 2 y 3: colaboradores de Empresa Uno.
            $this->assertSame('Empresa Uno', $detalle->getCell('A2')->getValue());
            $this->assertSame('Ana Perez', $detalle->getCell('D2')->getValue());
            $this->assertSame('Empresa Uno', $detalle->getCell('A3')->getValue());
            $this->assertSame('Bruno Gomez', $detalle->getCell('D3')->getValue());

            // Fila 4: subtotal de Empresa Uno.
            $this->assertSame('Total Empresa Uno (2 colaboradores)', $detalle->getCell('A4')->getValue());
            $this->assertSame(3000.0, $detalle->getCell('F4')->getValue());
            $this->assertSame(300.0, $detalle->getCell('L4')->getValue());
            $this->assertSame(2700.0, $detalle->getCell('N4')->getValue());
            $this->assertSame(270.0, $detalle->getCell('O4')->getValue());

            // Fila 5: colaborador de Empresa Dos.
            $this->assertSame('Empresa Dos', $detalle->getCell('A5')->getValue());
            $this->assertSame('Carla Ruiz', $detalle->getCell('D5')->getValue());

            // Fila 6: subtotal de Empresa Dos.
            $this->assertSame('Total Empresa Dos (1 colaboradores)', $detalle->getCell('A6')->getValue());
            $this->assertSame(1500.0, $detalle->getCell('F6')->getValue());
            $this->assertSame(150.0, $detalle->getCell('L6')->getValue());
            $this->assertSame(1350.0, $detalle->getCell('N6')->getValue());

            // Fila 7: total general.
            $this->assertSame('Total general (3 colaboradores)', $detalle->getCell('A7')->getValue());
            $this->assertSame(4500.0, $detalle->getCell('F7')->getValue());
            $this->assertSame(450.0, $detalle->getCell('L7')->getValue());
            $this->assertSame(4050.0, $detalle->getCell('N7')->getValue());
            $this->assertSame(405.0, $detalle->getCell('O7')->getValue());

            $ejecutivo = $libro->getSheetByName('Reporte_Ejecutivo');
            $this->assertNotNull($ejecutivo);
            $this->assertSame('Julio 2026', $ejecutivo->getCell('B3')->getValue());
            $this->assertSame(2, $ejecutivo->getCell('B4')->getValue());
            $this->assertSame(3, $ejecutivo->getCell('A9')->getValue());
            $this->assertSame(4050.0, $ejecutivo->getCell('E9')->getValue());
        } finally {
            unlink($ruta);
        }
    }

    private function boleta(Empresa $empresa, string $dni, string $nombres, string $apellidos, float $sueldo, float $aporte, float $essalud, float $neto): Boleta
    {
        $colaborador = (new Colaborador)->forceFill([
            'numero_documento' => $dni,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
        ]);

        $boleta = (new Boleta)->forceFill([
            'regimen_laboral_snapshot' => 'General',
            'total_ingresos' => number_format($sueldo, 2, '.', ''),
            'total_egresos' => number_format($aporte, 2, '.', ''),
            'neto_a_pagar' => number_format($neto, 2, '.', ''),
            'estado' => 'pagada',
        ]);
        $boleta->setRelation('colaborador', $colaborador);
        $boleta->setRelation('empresa', $empresa);
        $boleta->setRelation('conceptos', new Collection([
            $this->concepto('SUELDO_BASICO', 'ingreso', $sueldo),
            $this->concepto('AFP_APORTE_OBLIGATORIO', 'egreso', $aporte, baseUtilizada: $sueldo),
            $this->concepto('ESSALUD', 'aportacion', $essalud),
        ]));

        return $boleta;
    }

    private function concepto(string $codigo, string $tipo, float $monto, ?float $baseUtilizada = null): BoletaConcepto
    {
        $concepto = (new BoletaConcepto)->forceFill([
            'tipo' => $tipo,
            'monto' => number_format($monto, 2, '.', ''),
            'base_utilizada' => $baseUtilizada !== null ? number_format($baseUtilizada, 2, '.', '') : null,
        ]);
        $concepto->setRelation('concepto', (new ConceptoRemuneracion)->forceFill(['codigo' => $codigo]));

        return $concepto;
    }
}
