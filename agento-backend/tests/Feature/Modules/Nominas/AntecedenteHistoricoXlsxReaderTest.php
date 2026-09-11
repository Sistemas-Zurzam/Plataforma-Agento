<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Nominas\Infrastructure\AntecedenteHistoricoXlsxReader;
use Tests\TestCase;

class AntecedenteHistoricoXlsxReaderTest extends TestCase
{
    private function rutaFixture(string $nombre): string
    {
        return base_path("tests/Fixtures/{$nombre}");
    }

    public function test_lee_filas_de_ambas_hojas_con_documento_y_fecha_normalizados(): void
    {
        $filas = (new AntecedenteHistoricoXlsxReader)->leer($this->rutaFixture('antecedentes_historicos_validos.xlsx'));

        $this->assertNotEmpty($filas);

        $deDataPlanilla = collect($filas)->where('hoja_nombre', 'Data Planilla');
        $deDataProvisiones = collect($filas)->where('hoja_nombre', 'Data Provisiones');

        $this->assertSame(5, $deDataPlanilla->count());
        $this->assertSame(1, $deDataProvisiones->count());

        $primera = $deDataPlanilla->firstWhere('fila_numero', 2);
        $this->assertSame('10000001', $primera['numero_documento_normalizado']);
        $this->assertNull($primera['tipo_documento_normalizado']); // nunca se asume DNI
        $this->assertSame('2020-01-01', $primera['fecha_ingreso_vinculo']);
        $this->assertSame(1500.0, $primera['importe']);
        $this->assertSame('GRATIFICACION ORDINARIA', $primera['nombre_concepto_original']);
        $this->assertSame('Cancelado', $primera['estado_excel']);
        $this->assertIsArray($primera['datos_originales']);
    }

    public function test_fila_de_provisiones_queda_marcada_para_saltar_el_clasificador(): void
    {
        $filas = (new AntecedenteHistoricoXlsxReader)->leer($this->rutaFixture('antecedentes_historicos_validos.xlsx'));

        $provision = collect($filas)->firstWhere('hoja_nombre', 'Data Provisiones');

        $this->assertTrue($provision['es_provision']);
    }

    public function test_fila_con_monto_corrupto_no_numerico_queda_con_importe_nulo(): void
    {
        $filas = (new AntecedenteHistoricoXlsxReader)->leer($this->rutaFixture('antecedentes_historicos_con_errores.xlsx'));

        $filaCts = collect($filas)->where('hoja_nombre', 'Data Planilla')->firstWhere('nombre_concepto_original', 'CTS');

        $this->assertNull($filaCts['importe']);
    }
}
