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
    public function test_agrupa_por_empresa_con_subtotales_total_general_y_reintegros(): void
    {
        $empresaUno = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Uno']);
        $empresaDos = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Dos']);

        $ana = $this->boleta(1, $empresaUno, '10000001', 'Ana', 'Perez', sueldo: 1000, aporte: 100, essalud: 90, neto: 900);
        $bruno = $this->boleta(2, $empresaUno, '10000002', 'Bruno', 'Gomez', sueldo: 2000, aporte: 200, essalud: 180, neto: 1800);
        $carla = $this->boleta(3, $empresaDos, '20000001', 'Carla', 'Ruiz', sueldo: 1500, aporte: 150, essalud: 135, neto: 1350);

        // Reintegro aprobado de +50 solo para Ana, para verificar que se
        // refleje en su fila, en el subtotal de Empresa Uno y en el total
        // general, sin afectar a quien no tiene reintegros.
        $reintegrosPorBoleta = new Collection([1 => 50.0]);

        $boletas = new Collection([$ana, $bruno, $carla]);

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generar('Julio 2026', $boletas, $reintegrosPorBoleta);
        $ruta = dirname(__DIR__, 2).'/storage/framework/testing/reporte_ejecutivo_'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $libro = IOFactory::load($ruta);
            $detalle = $libro->getSheetByName('Detalle_Planilla');
            $this->assertNotNull($detalle);

            // Fila 2: Ana (Empresa Uno) — con reintegro de 50, neto 900+50=950.
            $this->assertSame('Empresa Uno', $detalle->getCell('A2')->getValue());
            $this->assertSame('Ana Perez', $detalle->getCell('D2')->getValue());
            $this->assertSame(50.0, $detalle->getCell('N2')->getValue());
            $this->assertSame(950.0, $detalle->getCell('O2')->getValue());

            // Fila 3: Bruno (Empresa Uno) — sin reintegro.
            $this->assertSame('Bruno Gomez', $detalle->getCell('D3')->getValue());
            $this->assertSame(0.0, $detalle->getCell('N3')->getValue());
            $this->assertSame(1800.0, $detalle->getCell('O3')->getValue());

            // Fila 4: subtotal de Empresa Uno.
            $this->assertSame('Total Empresa Uno (2 colaboradores)', $detalle->getCell('A4')->getValue());
            $this->assertSame(3000.0, $detalle->getCell('F4')->getValue());
            $this->assertSame(300.0, $detalle->getCell('L4')->getValue());
            $this->assertSame(50.0, $detalle->getCell('N4')->getValue());
            $this->assertSame(2750.0, $detalle->getCell('O4')->getValue());
            $this->assertSame(270.0, $detalle->getCell('P4')->getValue());

            // Fila 5: colaborador de Empresa Dos.
            $this->assertSame('Empresa Dos', $detalle->getCell('A5')->getValue());
            $this->assertSame('Carla Ruiz', $detalle->getCell('D5')->getValue());

            // Fila 6: subtotal de Empresa Dos — sin reintegros.
            $this->assertSame('Total Empresa Dos (1 colaboradores)', $detalle->getCell('A6')->getValue());
            $this->assertSame(0.0, $detalle->getCell('N6')->getValue());
            $this->assertSame(1350.0, $detalle->getCell('O6')->getValue());

            // Fila 7: total general.
            $this->assertSame('Total general (3 colaboradores)', $detalle->getCell('A7')->getValue());
            $this->assertSame(4500.0, $detalle->getCell('F7')->getValue());
            $this->assertSame(450.0, $detalle->getCell('L7')->getValue());
            $this->assertSame(50.0, $detalle->getCell('N7')->getValue());
            $this->assertSame(4100.0, $detalle->getCell('O7')->getValue());
            $this->assertSame(405.0, $detalle->getCell('P7')->getValue());

            $ejecutivo = $libro->getSheetByName('Reporte_Ejecutivo');
            $this->assertNotNull($ejecutivo);
            $this->assertSame('Julio 2026', $ejecutivo->getCell('B3')->getValue());
            $this->assertSame(2, $ejecutivo->getCell('B4')->getValue());
            $this->assertSame(3, $ejecutivo->getCell('A9')->getValue());
            $this->assertSame(50.0, $ejecutivo->getCell('E9')->getValue());
            $this->assertSame(4100.0, $ejecutivo->getCell('F9')->getValue());
        } finally {
            unlink($ruta);
        }
    }

    private function boleta(int $id, Empresa $empresa, string $dni, string $nombres, string $apellidos, float $sueldo, float $aporte, float $essalud, float $neto): Boleta
    {
        $colaborador = (new Colaborador)->forceFill([
            'numero_documento' => $dni,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
        ]);

        $boleta = (new Boleta)->forceFill([
            'id' => $id,
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
