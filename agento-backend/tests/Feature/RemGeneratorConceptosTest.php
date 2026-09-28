<?php

namespace Tests\Feature;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\SunatMapeoLookup;
use App\Modules\Nominas\Infrastructure\Plame\Export\RemGenerator;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Personas\Models\Colaborador;
use Database\Seeders\SunatMapeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemGeneratorConceptosTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_quinta_categoria_y_sis_con_codigo_y_monto(): void
    {
        $this->seed(SunatMapeoSeeder::class);

        $colaborador = new Colaborador([
            'tipo_documento' => 'dni',
            'numero_documento' => '12345678',
        ]);
        $colaborador->id = 1;
        $boleta = new Boleta();
        $boleta->setRelation('colaborador', $colaborador);
        $boleta->setRelation('conceptos', collect([
            $this->linea('RENTA_5TA', '0605', '125.50'),
            $this->linea('SIS_APORTACION', '0811', '20.00'),
            $this->linea('ESSALUD', '0804', '101.70'),
        ]));
        $contexto = new PlameExportContext(
            new Empresa(),
            new CicloRemunerativo(),
            collect([$boleta]),
            collect(),
            SunatMapeoLookup::cargar(),
        );

        $filas = (new RemGenerator())->generar($contexto);

        $this->assertContains(['01', '12345678', '0605', '125.50', '125.50'], $filas);
        $this->assertContains(['01', '12345678', '0811', '20.00', '20.00'], $filas);
        $this->assertContains(['01', '12345678', '0804', '101.70', '101.70'], $filas);
    }

    private function linea(string $codigoInterno, string $codigoPlame, string $monto): BoletaConcepto
    {
        $concepto = new ConceptoRemuneracion(['codigo' => $codigoInterno]);
        $linea = new BoletaConcepto([
            'codigo_plame_snapshot' => $codigoPlame,
            'monto_devengado' => $monto,
            'monto_pagado_descontado' => $monto,
        ]);
        $linea->setRelation('concepto', $concepto);

        return $linea;
    }
}
