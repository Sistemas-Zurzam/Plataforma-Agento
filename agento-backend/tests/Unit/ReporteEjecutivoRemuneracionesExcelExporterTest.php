<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\ReporteEjecutivo\Export\ReporteEjecutivoRemuneracionesExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class ReporteEjecutivoRemuneracionesExcelExporterTest extends TestCase
{
    /**
     * Columnas esperadas (A–G fijas): H Sueldo básico, I Bono de
     * productividad, J Total bonos, K–M HE 25/35/100, N Total HE,
     * O Total ingresos, P Aporte AFP/ONP, Q Prima, R Comisión, S Total AFP,
     * T Adelanto de sueldo, U Total otros descuentos, V Total descuentos,
     * W Neto, X ESSALUD, Y Costo empresa, Z Estado.
     */
    public function test_detalla_bonos_he_y_descuentos_por_concepto_agrupado_por_empresa(): void
    {
        $empresaUno = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Uno']);
        $empresaDos = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Dos']);

        $boletas = new Collection([
            $this->boleta(1, $empresaUno, 'Ana', 'Perez'),
            $this->boleta(2, $empresaUno, 'Bruno', 'Gomez'),
            $this->boleta(3, $empresaDos, 'Carla', 'Ruiz'),
        ]);

        $conceptosPorBoleta = new Collection([
            1 => collect([
                $this->linea('ingreso', 'SUELDO_BASICO', 1000),
                // Reintegro ya incorporado a sus conceptos: bono y HE de la complementaria.
                $this->linea('ingreso', 'BONIFICACION', 100, definicionId: 5, definicion: 'Bono de productividad'),
                $this->linea('ingreso', 'HE_25', 50),
                $this->linea('egreso', 'AFP_APORTE_OBLIGATORIO', 115),
                $this->linea('egreso', 'ADELANTO_SUELDO', 200, nombre: 'Adelanto de sueldo'),
                $this->linea('aportacion', 'ESSALUD', 90),
            ]),
            2 => collect([
                $this->linea('ingreso', 'SUELDO_BASICO', 2000),
                $this->linea('egreso', 'AFP_APORTE_OBLIGATORIO', 200),
                $this->linea('aportacion', 'ESSALUD', 180),
            ]),
            3 => collect([
                $this->linea('ingreso', 'SUELDO_BASICO', 1500),
                $this->linea('egreso', 'ONP', 150),
                $this->linea('aportacion', 'ESSALUD', 135),
            ]),
        ]);

        $contenido = ReporteEjecutivoRemuneracionesExcelExporter::generar('Julio 2026', $boletas, $conceptosPorBoleta);
        $ruta = dirname(__DIR__, 2).'/storage/framework/testing/reporte_ejecutivo_'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $libro = IOFactory::load($ruta);
            $detalle = $libro->getSheetByName('Detalle_Planilla');
            $this->assertNotNull($detalle);

            $encabezados = $detalle->rangeToArray('A2:Z2')[0];
            $this->assertSame(['Empresa', 'Periodo', 'DNI', 'Colaborador', 'Área', 'Cargo', 'Tipo', 'Sueldo básico', 'Bono de productividad', 'Total bonos',
                'HE 25%', 'HE 35%', 'HE 100%', 'Total horas extra', 'Total ingresos', 'Aporte AFP / ONP', 'Prima seguro', 'Comisión AFP', 'Total AFP / ONP',
                'Adelanto de sueldo', 'Total otros descuentos', 'Total descuentos', 'Neto a pagar', 'ESSALUD', 'Costo empresa', 'Estado'], $encabezados);
            $this->assertSame('Bonos', $detalle->getCell('I1')->getValue());
            $this->assertSame('Horas extra', $detalle->getCell('K1')->getValue());
            $this->assertSame('Otros descuentos', $detalle->getCell('T1')->getValue());

            // Fila 3: Ana.
            $this->assertSame('Ana Perez', $detalle->getCell('D3')->getValue());
            $this->assertSame('Ventas', $detalle->getCell('E3')->getValue());
            $this->assertSame('Asesor', $detalle->getCell('F3')->getValue());
            $this->assertSame(100.0, $detalle->getCell('I3')->getValue());
            $this->assertSame(100.0, $detalle->getCell('J3')->getValue());
            $this->assertSame(50.0, $detalle->getCell('K3')->getValue());
            $this->assertSame(50.0, $detalle->getCell('N3')->getValue());
            $this->assertSame(1150.0, $detalle->getCell('O3')->getValue());
            $this->assertSame(200.0, $detalle->getCell('T3')->getValue());
            $this->assertSame(315.0, $detalle->getCell('V3')->getValue());
            $this->assertSame(835.0, $detalle->getCell('W3')->getValue());
            $this->assertSame(1240.0, $detalle->getCell('Y3')->getValue());

            // Fila 4: Bruno — sin bonos ni descuentos adicionales.
            $this->assertSame('Bruno Gomez', $detalle->getCell('D4')->getValue());
            $this->assertSame(0.0, $detalle->getCell('J4')->getValue());
            $this->assertSame(1800.0, $detalle->getCell('W4')->getValue());

            // Fila 5: subtotal de Empresa Uno.
            $this->assertSame('Total Empresa Uno (2 colaboradores)', $detalle->getCell('A5')->getValue());
            $this->assertSame(3000.0, $detalle->getCell('H5')->getValue());
            $this->assertSame(3150.0, $detalle->getCell('O5')->getValue());
            $this->assertSame(315.0, $detalle->getCell('S5')->getValue());
            $this->assertSame(2635.0, $detalle->getCell('W5')->getValue());

            // Fila 6–7: Empresa Dos (ONP va en la misma columna de aporte).
            $this->assertSame('Carla Ruiz', $detalle->getCell('D6')->getValue());
            $this->assertSame(150.0, $detalle->getCell('P6')->getValue());
            $this->assertSame('Total Empresa Dos (1 colaboradores)', $detalle->getCell('A7')->getValue());

            // Fila 8: total general.
            $this->assertSame('Total general (3 colaboradores)', $detalle->getCell('A8')->getValue());
            $this->assertSame(4650.0, $detalle->getCell('O8')->getValue());
            $this->assertSame(665.0, $detalle->getCell('V8')->getValue());
            $this->assertSame(3985.0, $detalle->getCell('W8')->getValue());
            $this->assertSame(405.0, $detalle->getCell('X8')->getValue());

            $ejecutivo = $libro->getSheetByName('Reporte_Ejecutivo');
            $this->assertNotNull($ejecutivo);
            $this->assertSame('Julio 2026', $ejecutivo->getCell('B3')->getValue());
            $this->assertSame(2, $ejecutivo->getCell('B4')->getValue());
            $this->assertSame(['Colaboradores', 'Bruto', 'Descuentos', 'Total AFP / ONP', 'Neto pagado', 'ESSALUD', 'Costo empresa'], $ejecutivo->rangeToArray('A8:G8')[0]);
            $this->assertSame(4650.0, $ejecutivo->getCell('B9')->getValue());
            $this->assertSame(3985.0, $ejecutivo->getCell('E9')->getValue());
        } finally {
            unlink($ruta);
        }
    }

    private function boleta(int $id, Empresa $empresa, string $nombres, string $apellidos): Boleta
    {
        $colaborador = (new Colaborador)->forceFill([
            'numero_documento' => '1000000'.$id,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'cargo' => 'Asesor',
        ]);
        $colaborador->setRelation('area', (new Area)->forceFill(['nombre' => 'Ventas']));

        $boleta = (new Boleta)->forceFill([
            'id' => $id,
            'regimen_laboral_snapshot' => 'General',
            'estado' => 'pagada',
        ]);
        $boleta->setRelation('colaborador', $colaborador);
        $boleta->setRelation('empresa', $empresa);
        $boleta->setRelation('conceptos', new Collection);

        return $boleta;
    }

    /** @return array{tipo: string, codigo: string, nombre: string, definicion_id: ?int, definicion: ?string, monto: float} */
    private function linea(string $tipo, string $codigo, float $monto, ?int $definicionId = null, ?string $definicion = null, ?string $nombre = null): array
    {
        return [
            'tipo' => $tipo,
            'codigo' => $codigo,
            'nombre' => $nombre ?? $codigo,
            'definicion_id' => $definicionId,
            'definicion' => $definicion,
            'monto' => $monto,
        ];
    }
}
