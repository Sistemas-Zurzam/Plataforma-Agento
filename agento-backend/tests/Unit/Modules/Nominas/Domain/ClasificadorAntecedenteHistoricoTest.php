<?php

namespace Tests\Unit\Modules\Nominas\Domain;

use App\Modules\Nominas\Domain\ClasificadorAntecedenteHistorico;
use PHPUnit\Framework\TestCase;

class ClasificadorAntecedenteHistoricoTest extends TestCase
{
    private ClasificadorAntecedenteHistorico $clasificador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clasificador = new ClasificadorAntecedenteHistorico;
    }

    public function test_locador_con_concepto_de_beneficio_es_no_aplicable(): void
    {
        $resultado = $this->clasificador->clasificar('PLANILLA', 'GRATIFICACION ORDINARIA', 'Cancelado', esLocador: true);

        $this->assertSame('no_aplicable', $resultado['clasificacion']);
    }

    public function test_liquidacion_siempre_es_no_aplicable_con_tipo_antecedente_liquidacion_historica(): void
    {
        foreach (['CTS', 'GRATIFICACION TRUNCA', 'VACACIONES TRUNCAS', 'AFP FONDO'] as $concepto) {
            $resultado = $this->clasificador->clasificar('LIQUIDACION', $concepto, 'Cancelado', esLocador: false);

            $this->assertSame('no_aplicable', $resultado['clasificacion'], "Concepto: {$concepto}");
            $this->assertSame('liquidacion_historica', $resultado['tipo_antecedente']);
        }
    }

    public function test_estado_neto_pendiente_es_siempre_observado(): void
    {
        $resultado = $this->clasificador->clasificar('PLANILLA', 'GRATIFICACION ORDINARIA', 'Pendiente Pago', esLocador: false);

        $this->assertSame('observado', $resultado['clasificacion']);
    }

    public function test_cts_cancelada_es_observado_nunca_aplicable_automaticamente(): void
    {
        $resultado = $this->clasificador->clasificar('CTS', 'CTS', 'Cancelado', esLocador: false);

        $this->assertSame('observado', $resultado['clasificacion']);
        $this->assertSame('cts_depositada', $resultado['tipo_antecedente']);
        $this->assertNotEmpty($resultado['advertencias']);
    }

    public function test_gratificacion_ordinaria_cancelada_es_observado_nunca_aplicable_automaticamente(): void
    {
        $resultado = $this->clasificador->clasificar('PLANILLA', 'GRATIFICACION ORDINARIA', 'Cancelado', esLocador: false);

        $this->assertSame('observado', $resultado['clasificacion']);
        $this->assertSame('gratificacion_pagada', $resultado['tipo_antecedente']);
    }

    public function test_conceptos_de_planilla_ordinaria_son_no_aplicable(): void
    {
        $conceptos = ['BASICO', 'AFP FONDO', 'AFP SEGURO', 'TARDANZA', 'ESSALUD', 'COMEDOR',
            'HORAS EXTRAS 25%', 'ADELANTO SUELDO', 'ADELANTO GRATIFICACION', 'NETO', 'ONP'];

        foreach ($conceptos as $concepto) {
            $resultado = $this->clasificador->clasificar('PLANILLA', $concepto, 'Cancelado', esLocador: false);
            $this->assertSame('no_aplicable', $resultado['clasificacion'], "Concepto: {$concepto}");
        }
    }

    public function test_provision_siempre_es_no_aplicable(): void
    {
        $resultado = $this->clasificador->clasificar('PROV. CTS', 'PROV. CTS', 'Cancelado', esLocador: false);

        $this->assertSame('no_aplicable', $resultado['clasificacion']);
    }

    public function test_concepto_desconocido_es_observado_nunca_error_ni_no_aplicable_por_defecto(): void
    {
        $resultado = $this->clasificador->clasificar('PLANILLA', 'CONCEPTO INVENTADO XYZ', 'Cancelado', esLocador: false);

        $this->assertSame('observado', $resultado['clasificacion']);
    }
}
