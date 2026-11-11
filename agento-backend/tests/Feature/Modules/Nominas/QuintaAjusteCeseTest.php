<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\QuintaAjusteCese;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Ajuste final de quinta del mes del cese aplicado una sola vez entre la
 * liquidación y la boleta de agosto (Reglamento LIR arts. 41 d y 42 a).
 *
 * Datos del informe: sueldo 10000 desde el 01/01/2026, boletas ene–jul con
 * 10000 gravado, gratificación de julio 10000 + 900 (Beneficios Sociales),
 * cese 15/08/2026. Gravado de la liquidación: grat. trunca 1666.67 + bonif.
 * 150 + vacaciones truncas 6250 = 8066.67. Boleta de agosto: 5000 (15 días),
 * ONP 650.
 * Impuesto anual con todo (70000 + 10900 + 5000 + 8066.67 − 38500 =
 * 55466.67): 27500 × 8 % + 27966.67 × 14 % = 6115.33. Ese es el total que
 * debe quedar retenido en el año en cualquier orden.
 */
class QuintaAjusteCeseTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private const IMPUESTO_ANUAL = 6115.33;

    // Netos sin 5ta: liquidación 11469.45 de ingresos − 812.50 de ONP sobre vacaciones; boleta 5000 − 650.
    private const NETO_LIQUIDACION_SIN_5TA = 10656.95;

    private const NETO_BOLETA_SIN_5TA = 4350.0;

    private Empresa $empresa;

    private Colaborador $colaborador;

    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11'));
        $this->seed(DatabaseSeeder::class);
    }

    private function preparar(float $retencionMensual = 600, ?float $quintaBoletaAgostoEmitida = null): void
    {
        $this->empresa = Empresa::factory()->create(['regimen_laboral' => 'General']);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($this->empresa);
        $c = $this->crearColaborador($this->empresa, ['fecha_ingreso' => '2026-01-01', 'regimen_laboral' => 'General']);
        $c->remuneraciones()->update(['salario' => 10000, 'vigencia_desde' => '2026-01-01']);
        foreach (range(1, 7) as $m) {
            $this->boletaEmitida($c, $m, 10000, $retencionMensual);
        }
        if ($quintaBoletaAgostoEmitida !== null) {
            $this->boletaEmitida($c, 8, 5000, $quintaBoletaAgostoEmitida);
        }
        $beneficio = BeneficioSocial::create(['empresa_id' => $this->empresa->id, 'tipo' => 'gratificacion_julio', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
            'total_colaboradores' => 1, 'total_bruto' => 10000, 'total_neto' => 10900, 'estado' => 'pagado', 'calculado_at' => '2026-07-10', 'pagado_at' => '2026-07-15']);
        BeneficioSocialDetalle::create(['beneficio_social_id' => $beneficio->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 10000, 'meses' => 6,
            'bruta' => 10000, 'bonificacion_extraordinaria' => 900, 'neta' => 10900]);
        $this->colaborador = app(ColaboradorService::class)->cesar($this->empresa, $c, '2026-08-15', 'Renuncia', 'renuncia')->fresh();
        $this->usuario = User::factory()->create(['empresa_id' => $this->empresa->id])->id;
        if ($quintaBoletaAgostoEmitida === null) {
            CicloRemunerativo::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Agosto', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
                'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'abierto']);
        }
    }

    /** Boleta emitida (pagada) con una retención de 5ta ya practicada, sin pasar por el ajuste. */
    private function boletaEmitida(Colaborador $c, int $mes, float $gravado, float $quinta): void
    {
        $inicio = Carbon::create(2026, $mes, 1);
        $ciclo = CicloRemunerativo::create(['empresa_id' => $this->empresa->id, 'nombre' => "M{$mes}", 'fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $inicio->copy()->endOfMonth()->toDateString(),
            'fecha_corte_asistencia' => $inicio->copy()->endOfMonth()->toDateString(), 'fecha_pago' => $inicio->copy()->endOfMonth()->toDateString(), 'estado' => 'pagado']);
        $b = Boleta::create(['empresa_id' => $this->empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $c->id, 'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 10000,
            'dias_pagados' => 30, 'total_ingresos' => $gravado, 'total_egresos' => $quinta, 'total_aportaciones' => 0, 'neto_a_pagar' => $gravado - $quinta, 'estado' => 'pagada',
            'es_version_vigente' => true, 'snapshot_parametros_version' => 't', 'snapshot_reglas_version' => 't', 'calculado_at' => $inicio]);
        foreach ([['SUELDO_BASICO', 'ingreso', $gravado], ['RENTA_5TA', 'egreso', $quinta]] as [$codigo, $tipo, $monto]) {
            $b->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id, 'tipo' => $tipo,
                'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso', 'monto' => $monto, 'formula_texto' => 'emitida']);
        }
    }

    private function propuesta(array $datos = []): array
    {
        return app(LiquidacionCeseService::class)->propuesta($this->empresa, $this->colaborador->fresh(), ['dias_vacaciones_gozados' => 0, ...$datos]);
    }

    private function liquidar(array $datos = [], ?array $propuesta = null): LiquidacionCese
    {
        $p = $propuesta ?? $this->propuesta($datos);
        $lineas = collect($p['conceptos'])->map(fn ($l) => ['codigo' => $l['codigo'], 'monto' => $l['monto']])->all();
        $datos = ['dias_vacaciones_gozados' => 0, ...$datos];

        return app(LiquidacionCeseService::class)->registrar($this->empresa, $this->colaborador->fresh(), 'asistido', $lineas, 'Decisión validada (prueba)', $this->usuario, $datos)->fresh('conceptos');
    }

    private function aprobar(LiquidacionCese $liquidacion): void
    {
        $liquidacion->update(['estado' => 'aprobada']);
        app(LiquidacionCeseService::class)->actualizarEstadoQuinta($liquidacion, 'emitido');
    }

    private function boletaAgosto(): Boleta
    {
        $ciclo = CicloRemunerativo::where('empresa_id', $this->empresa->id)->where('nombre', 'Agosto')->firstOrFail();
        app(BoletaService::class)->calcularPlanilla($this->empresa, $ciclo, $this->usuario);

        return Boleta::where('ciclo_id', $ciclo->id)->where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->with('conceptos.concepto')->firstOrFail();
    }

    private static function quinta($lineas): float
    {
        $codigo = fn ($l) => $l->codigo ?? $l->concepto?->codigo;

        return round((float) collect($lineas)->filter(fn ($l) => $codigo($l) === 'RENTA_5TA')->sum('monto')
            - (float) collect($lineas)->filter(fn ($l) => $codigo($l) === 'DEVOLUCION_RENTA_5TA')->sum('monto'), 2);
    }

    /** Total retenido en el año = boletas vigentes + liquidación vigente (retención − devolución). */
    private function totalDelAnio(): float
    {
        $boletas = Boleta::where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->with('conceptos.concepto')->get()
            ->sum(fn ($b) => self::quinta($b->conceptos));
        $liquidacion = LiquidacionCese::where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->where('estado', '!=', 'anulada')->with('conceptos')->first();

        return round($boletas + ($liquidacion ? self::quinta($liquidacion->conceptos) : 0), 2);
    }

    private function registro(): QuintaAjusteCese
    {
        $this->assertSame(1, QuintaAjusteCese::where('colaborador_id', $this->colaborador->id)->count());

        return QuintaAjusteCese::where('colaborador_id', $this->colaborador->id)->firstOrFail();
    }

    public function test_liquidacion_primero_aplica_el_ajuste_y_la_boleta_solo_concilia_sin_proyeccion(): void
    {
        $this->preparar();
        $liquidacion = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        // Regresión del informe: 70000 + 10900 + 8066.67 − 38500 → 5415.33 − 4200 = 1215.33.
        $this->assertSame(1215.33, self::quinta($liquidacion->conceptos));
        $this->aprobar($liquidacion);

        $boleta = $this->boletaAgosto();
        // Solo la diferencia por sus 5000: 6115.33 − 4200 − 1215.33 = 700 (antes proyectaba 1298.67).
        $this->assertSame(700.0, self::quinta($boleta->conceptos));
        $this->assertEquals(self::NETO_LIQUIDACION_SIN_5TA - 1215.33, (float) $liquidacion->neto_pagar);
        $this->assertEquals(self::NETO_BOLETA_SIN_5TA - 700, (float) $boleta->neto_a_pagar);
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());

        $registro = $this->registro();
        $this->assertSame(QuintaAjusteCese::LIQUIDACION, $registro->documento_asignado);
        $this->assertSame('aplicado', $registro->estado);
        $this->assertSame(1215.33, $registro->aplicaciones['liquidacion']['monto']);
        $this->assertEquals(700.0, $registro->aplicaciones['boleta']['monto']);
        $this->assertSame(1215.33, $registro->aplicaciones['boleta']['desglose']['aplicado_otro_documento']);
    }

    public function test_boleta_primero_aplica_el_ajuste_y_la_liquidacion_concilia_sin_decision(): void
    {
        $this->preparar();
        $boleta = $this->boletaAgosto();
        // Sin la liquidación: 70000 + 10900 + 5000 − 38500 = 47400 → 4986 − 4200 = 786.
        $this->assertSame(786.0, self::quinta($boleta->conceptos));

        $propuesta = $this->propuesta();
        $this->assertNull(collect($propuesta['validaciones'])->firstWhere('clave', 'decision_quinta_boleta_cese'));
        $liquidacion = $this->liquidar();
        // Concilia: 6115.33 − 4200 − 786 = 1129.33.
        $this->assertSame(1129.33, self::quinta($liquidacion->conceptos));
        $this->assertSame([], app(LiquidacionCeseService::class)->validacionesPendientes($liquidacion));
        $this->assertEquals(self::NETO_LIQUIDACION_SIN_5TA - 1129.33, (float) $liquidacion->neto_pagar);
        $this->assertEquals(self::NETO_BOLETA_SIN_5TA - 786, (float) $boleta->neto_a_pagar);
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
        $this->assertSame(QuintaAjusteCese::BOLETA, $this->registro()->documento_asignado);
    }

    public function test_ajuste_delegado_a_la_boleta_la_liquidacion_muestra_donde_se_aplicara(): void
    {
        $this->preparar();
        $liquidacion = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_boleta_del_cese']);
        $this->assertSame(0.0, self::quinta($liquidacion->conceptos));
        $this->assertTrue(collect($liquidacion->alertas)->contains(fn ($a) => str_contains($a, 'se aplicará en la boleta del mes del cese')));

        $boleta = $this->boletaAgosto();
        // Ajuste final completo en la boleta: 6115.33 − 4200 = 1915.33 (antes proyectaba 1298.67).
        $this->assertSame(1915.33, self::quinta($boleta->conceptos));
        $this->assertEquals(self::NETO_LIQUIDACION_SIN_5TA, (float) $liquidacion->neto_pagar);
        $this->assertEquals(self::NETO_BOLETA_SIN_5TA - 1915.33, (float) $boleta->neto_a_pagar);
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
        $this->assertSame(QuintaAjusteCese::BOLETA, $this->registro()->documento_asignado);
    }

    public function test_boleta_del_cese_ya_emitida_se_concilia_como_retencion_previa_sin_tocarla(): void
    {
        // Boleta de agosto emitida antes del cambio con la proyección mensual (1298.67).
        $this->preparar(600, 1298.67);
        $emitida = Boleta::where('colaborador_id', $this->colaborador->id)->whereHas('ciclo', fn ($q) => $q->where('nombre', 'M8'))->firstOrFail();

        $liquidacion = $this->liquidar();
        // 6115.33 − (4200 + 1298.67) = 616.66; la boleta emitida no cambia.
        $this->assertSame(616.66, self::quinta($liquidacion->conceptos));
        $this->assertSame(1298.67, self::quinta($emitida->fresh()->load('conceptos.concepto')->conceptos));
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
    }

    public function test_devolucion_por_exceso_y_conciliacion_posterior_de_la_boleta(): void
    {
        $this->preparar(900);
        $liquidacion = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        // Regresión del informe: 5415.33 − 6300 → devolución de 884.67.
        $this->assertSame(-884.67, self::quinta($liquidacion->conceptos));
        $this->assertEquals(self::NETO_LIQUIDACION_SIN_5TA + 884.67, (float) $liquidacion->neto_pagar);

        $boleta = $this->boletaAgosto();
        // 6115.33 − (6300 − 884.67) = 700.
        $this->assertSame(700.0, self::quinta($boleta->conceptos));
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
    }

    public function test_reintentos_no_duplican_el_ajuste(): void
    {
        $this->preparar();
        $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        $segunda = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        $this->boletaAgosto();
        $boleta = $this->boletaAgosto();

        $registro = $this->registro();
        $this->assertSame(['boleta', 'liquidacion'], collect($registro->aplicaciones)->keys()->sort()->values()->all());
        $this->assertSame($segunda->id, $registro->aplicaciones['liquidacion']['id']);
        $this->assertSame($boleta->id, $registro->aplicaciones['boleta']['id']);
        $this->assertSame(2, $registro->aplicaciones['boleta']['version']);
        $this->assertSame(700.0, self::quinta($boleta->conceptos));
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
    }

    public function test_boleta_calculada_entre_la_vista_previa_y_el_registro_de_la_liquidacion(): void
    {
        $this->preparar();
        // RR.HH. abre la liquidación (sin boleta: propone 1215.33) …
        $vistaPrevia = $this->propuesta(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        // … mientras la planilla de agosto se calcula y aplica el ajuste (786).
        $this->assertSame(786.0, self::quinta($this->boletaAgosto()->conceptos));

        try {
            $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion'], $vistaPrevia);
            $this->fail('Registrar con la 5ta de una vista previa desactualizada debe rechazarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('La renta de 5ta del cese cambió', collect($e->errors())->flatten()->first());
        }

        $liquidacion = $this->liquidar();
        $this->assertSame(1129.33, self::quinta($liquidacion->conceptos));
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
    }

    public function test_anular_la_liquidacion_retira_su_aplicacion(): void
    {
        $this->preparar();
        $liquidacion = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        $liquidacion->update(['estado' => 'anulada', 'es_version_vigente' => false]);
        app(LiquidacionCeseService::class)->actualizarEstadoQuinta($liquidacion, 'anulado');
        $this->assertArrayNotHasKey('liquidacion', $this->registro()->aplicaciones);

        // La boleta no concilia contra una liquidación anulada: difiere a la nueva liquidación.
        $this->assertSame(0.0, self::quinta($this->boletaAgosto()->conceptos));
        $nueva = $this->liquidar(['decision_quinta_boleta_cese' => 'ajuste_en_liquidacion']);
        $this->assertSame(1915.33, self::quinta($nueva->conceptos));
        $this->assertSame(self::IMPUESTO_ANUAL, $this->totalDelAnio());
    }
}
