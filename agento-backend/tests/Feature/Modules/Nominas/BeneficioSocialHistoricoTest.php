<?php

namespace Tests\Feature\Modules\Nominas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class BeneficioSocialHistoricoTest extends TestCase
{
    use CreaColaboradorDePrueba, RefreshDatabase;

    public function test_la_migracion_crea_la_tabla_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('beneficios_sociales_historicos'));
        $this->assertTrue(Schema::hasColumns('beneficios_sociales_historicos', [
            'empresa_id', 'colaborador_id', 'importacion_detalle_id',
            'tipo', 'anio', 'periodo', 'fecha_periodo_inicio', 'fecha_periodo_fin', 'fecha_pago_deposito',
            'importe_bruto', 'importe_pagado', 'estado',
            'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'fecha_corte',
            'origen', 'referencia_externa', 'observaciones',
            'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
        ]));
    }

    public function test_guarda_correctamente_sus_casts_y_relaciones(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $beneficio = BeneficioSocialHistorico::factory()->create([
            'empresa_id' => $empresa->id,
            'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'importe_bruto' => 1500.5,
        ]);

        $this->assertTrue($beneficio->colaborador->is($colaborador));
        $this->assertTrue($beneficio->empresa->is($empresa));
        $this->assertSame('1500.50', $beneficio->fresh()->importe_bruto);
    }

    public function test_no_se_duplica_el_mismo_beneficio_para_el_mismo_vinculo_tipo_anio_y_periodo(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $datos = [
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo' => 'gratificacion_julio', 'anio' => 2026, 'periodo' => '2026-S1',
        ];

        BeneficioSocialHistorico::factory()->create($datos);

        $this->expectException(QueryException::class);
        BeneficioSocialHistorico::factory()->create($datos);
    }

    /**
     * Corrección post-revisión: anular una fila por error de digitación
     * debe liberar la combinación empresa+colaborador+vínculo+tipo+año+
     * periodo para que RR.HH. registre la versión corregida — antes del
     * endurecimiento (version/es_version_vigente), una fila anulada seguía
     * bloqueando esa combinación para siempre.
     */
    public function test_anular_un_beneficio_permite_registrar_la_version_corregida(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $datosBase = [
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo' => 'gratificacion_julio', 'anio' => 2026, 'periodo' => '2026-S1',
        ];

        $original = BeneficioSocialHistorico::factory()->create([...$datosBase, 'importe_bruto' => 1400]);
        $original->update(['estado' => 'anulado', 'es_version_vigente' => false, 'motivo_anulacion' => 'Monto digitado incorrectamente']);

        $corregido = BeneficioSocialHistorico::factory()->nuevaVersionDe($original->fresh())->create(['importe_bruto' => 1500]);

        $this->assertDatabaseHas('beneficios_sociales_historicos', ['id' => $original->id, 'es_version_vigente' => false, 'estado' => 'anulado']);
        $this->assertDatabaseHas('beneficios_sociales_historicos', ['id' => $corregido->id, 'es_version_vigente' => true, 'version' => 2, 'importe_bruto' => '1500.00']);
    }

    public function test_solo_puede_existir_una_version_vigente_a_la_vez(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        $datosBase = [
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
            'tipo' => 'gratificacion_julio', 'anio' => 2026, 'periodo' => '2026-S1',
        ];

        $original = BeneficioSocialHistorico::factory()->create($datosBase);

        // Intentar registrar una segunda versión VIGENTE sin anular la
        // primera debe seguir bloqueado — la corrección solo abre la
        // combinación cuando la anterior deja de ser vigente.
        $this->expectException(QueryException::class);
        BeneficioSocialHistorico::factory()->nuevaVersionDe($original)->create();
    }

    /**
     * Agento reutiliza el mismo colaborador_id tras una recontratación
     * (diagnóstico previo, LiquidacionCeseService.php:166-168) — la clave
     * de vínculo (empresa+colaborador+fecha_ingreso_vinculo) es lo que
     * permite que dos vínculos laborales distintos del MISMO colaborador
     * tengan cada uno su propio antecedente sin chocar entre sí.
     */
    public function test_una_recontratacion_con_otra_fecha_de_ingreso_puede_tener_su_propio_antecedente(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);

        $vinculoUno = BeneficioSocialHistorico::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => '2020-01-02',
            'tipo' => 'gratificacion_julio', 'anio' => 2021, 'periodo' => '2021-S1',
        ]);
        $vinculoDos = BeneficioSocialHistorico::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => '2025-03-01',
            'tipo' => 'gratificacion_julio', 'anio' => 2021, 'periodo' => '2021-S1',
        ]);

        $this->assertDatabaseHas('beneficios_sociales_historicos', ['id' => $vinculoUno->id]);
        $this->assertDatabaseHas('beneficios_sociales_historicos', ['id' => $vinculoDos->id]);
    }

    public function test_una_provision_no_puede_registrarse_como_beneficio_pagado(): void
    {
        $provisionValida = BeneficioSocialHistorico::factory()->make(['estado' => 'aprobado', 'importe_pagado' => null]);
        $this->assertTrue($provisionValida->esConsistente());

        $provisionConMontoPagado = BeneficioSocialHistorico::factory()->make(['estado' => 'aprobado', 'importe_pagado' => 1500]);
        $this->assertFalse($provisionConMontoPagado->esConsistente());

        $pagoSinMonto = BeneficioSocialHistorico::factory()->make(['estado' => 'pagado', 'importe_pagado' => null]);
        $this->assertFalse($pagoSinMonto->esConsistente());

        $pagoValido = BeneficioSocialHistorico::factory()->make(['estado' => 'pagado', 'importe_pagado' => 1500]);
        $this->assertTrue($pagoValido->esConsistente());
    }

    public function test_la_eliminacion_fisica_de_un_colaborador_con_antecedentes_queda_restringida(): void
    {
        $empresa = Empresa::factory()->create();
        $colaborador = $this->crearColaborador($empresa);
        BeneficioSocialHistorico::factory()->create([
            'empresa_id' => $empresa->id, 'colaborador_id' => $colaborador->id,
            'fecha_ingreso_vinculo' => $colaborador->fecha_ingreso,
        ]);

        $this->expectException(QueryException::class);
        $colaborador->forceDelete();
    }
}
