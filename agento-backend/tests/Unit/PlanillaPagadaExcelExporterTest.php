<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Infrastructure\PlanillaPagada\Export\PlanillaPagadaExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

class PlanillaPagadaExcelExporterTest extends TestCase
{
    public function test_genera_resumen_y_detalle_con_banco_historico(): void
    {
        $empresa = (new Empresa)->forceFill(['nombre_comercial' => 'Empresa Demo']);
        $ciclo = (new CicloRemunerativo)->forceFill(['nombre' => 'Agosto 2026']);
        $ciclo->setRelation('empresa', $empresa);

        $colaborador = (new Colaborador)->forceFill([
            'numero_documento' => '00123456',
            'nombres' => 'Ana María',
            'apellidos' => 'Pérez Soto',
        ]);
        $banco = (new Banco)->forceFill(['nombre' => 'Banco Histórico']);
        $datosPago = new BoletaDatosPago;
        $datosPago->setRelation('banco', $banco);

        $boleta = (new Boleta)->forceFill([
            'total_ingresos' => '1500.50',
            'total_egresos' => '250.25',
            'neto_a_pagar' => '1250.25',
        ]);
        $boleta->setRelation('colaborador', $colaborador);
        $boleta->setRelation('datosPago', $datosPago);

        $contenido = PlanillaPagadaExcelExporter::generar($ciclo, new Collection([$boleta]));
        $ruta = dirname(__DIR__, 2).'/storage/framework/testing/planilla_'.uniqid().'.xlsx';
        file_put_contents($ruta, $contenido);

        try {
            $hoja = IOFactory::load($ruta)->getActiveSheet();

            $this->assertSame('Empresa', $hoja->getCell('A1')->getValue());
            $this->assertSame('Empresa Demo', $hoja->getCell('B1')->getValue());
            $this->assertSame('Ingresos totales', $hoja->getCell('C1')->getValue());
            $this->assertSame(1500.5, $hoja->getCell('D1')->getValue());
            $this->assertSame(250.25, $hoja->getCell('F1')->getValue());
            $this->assertSame(1250.25, $hoja->getCell('H1')->getValue());
            $this->assertSame('00123456', $hoja->getCell('A4')->getValue());
            $this->assertSame('Ana María Pérez Soto', $hoja->getCell('B4')->getValue());
            $this->assertSame('Banco Histórico', $hoja->getCell('F4')->getValue());
        } finally {
            unlink($ruta);
        }
    }
}
