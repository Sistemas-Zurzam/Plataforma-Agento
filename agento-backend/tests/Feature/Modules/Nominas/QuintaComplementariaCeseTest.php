<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Application\Quinta\AjusteFinalQuintaCese;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Models\QuintaAjusteCese;
use App\Modules\Nominas\Services\BoletaService;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Complementarias de un colaborador cesado en el ejercicio dentro del ajuste
 * final de 5ta del cese (Reglamento LIR art. 41 inc. d: "todo otro ingreso
 * puesto a disposición"; art. 42 inc. a: devolución del exceso).
 *
 * Mismos datos que QuintaAjusteCeseTest: sueldo 10000, ene–jul retenidos
 * 600/mes, gratificación de julio 10000 + 900, cese 15/08/2026, liquidación
 * gravada 8066.67 y boleta de agosto 5000. Sin complementaria el impuesto del
 * año es 6115.33 (regresión del caso original).
 *
 * Con un bono no remunerativo de 1000 afecto a 5ta, calculado aparte:
 * 70000 + 10900 + 5000 + 8066.67 + 1000 = 94966.67 − 38500 = 56466.67 →
 * 27500 × 8 % + 28966.67 × 14 % = 2200 + 4055.33 = 6255.33. La renta nueva
 * cae íntegra en el tramo del 14 %: 1000 × 14 % = 140.
 */
class QuintaComplementariaCeseTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private const IMPUESTO_SIN_BONO = 6115.33;

    private const IMPUESTO_CON_BONO = 6255.33;

    private Empresa $empresa;

    private Colaborador $colaborador;

    private int $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11'));
        $this->seed(DatabaseSeeder::class);
    }

    private function preparar(): void
    {
        $this->empresa = Empresa::factory()->create(['regimen_laboral' => 'General']);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($this->empresa);
        $c = $this->crearColaborador($this->empresa, ['fecha_ingreso' => '2026-01-01', 'regimen_laboral' => 'General']);
        $c->remuneraciones()->update(['salario' => 10000, 'vigencia_desde' => '2026-01-01']);
        foreach (range(1, 7) as $m) {
            $this->boletaEmitida($c, $m, 10000, 600);
        }
        $beneficio = BeneficioSocial::create(['empresa_id' => $this->empresa->id, 'tipo' => 'gratificacion_julio', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
            'total_colaboradores' => 1, 'total_bruto' => 10000, 'total_neto' => 10900, 'estado' => 'pagado', 'calculado_at' => '2026-07-10', 'pagado_at' => '2026-07-15']);
        BeneficioSocialDetalle::create(['beneficio_social_id' => $beneficio->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 10000, 'meses' => 6,
            'bruta' => 10000, 'bonificacion_extraordinaria' => 900, 'neta' => 10900]);
        $this->colaborador = app(ColaboradorService::class)->cesar($this->empresa, $c, '2026-08-15', 'Renuncia', 'renuncia')->fresh();
        $this->usuario = User::factory()->create(['empresa_id' => $this->empresa->id])->id;
        CicloRemunerativo::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Agosto', 'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'abierto']);
    }

    private function boletaEmitida(Colaborador $c, int $mes, float $gravado, float $quinta, ?string $nombre = null): void
    {
        $inicio = Carbon::create(2026, $mes, 1);
        $ciclo = CicloRemunerativo::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre ?? "M{$mes}", 'fecha_inicio' => $inicio->toDateString(), 'fecha_fin' => $inicio->copy()->endOfMonth()->toDateString(),
            'fecha_corte_asistencia' => $inicio->copy()->endOfMonth()->toDateString(), 'fecha_pago' => $inicio->copy()->endOfMonth()->toDateString(), 'estado' => 'pagado']);
        $b = Boleta::create(['empresa_id' => $this->empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $c->id, 'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 10000,
            'dias_pagados' => 30, 'total_ingresos' => $gravado, 'total_egresos' => $quinta, 'total_aportaciones' => 0, 'neto_a_pagar' => $gravado - $quinta, 'estado' => 'pagada',
            'es_version_vigente' => true, 'snapshot_parametros_version' => 't', 'snapshot_reglas_version' => 't', 'calculado_at' => $inicio]);
        foreach ([['SUELDO_BASICO', 'ingreso', $gravado], ['RENTA_5TA', 'egreso', $quinta]] as [$codigo, $tipo, $monto]) {
            $b->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id, 'tipo' => $tipo,
                'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso', 'monto' => $monto, 'formula_texto' => 'emitida']);
        }
    }

    private function liquidar(bool $aprobar = true): LiquidacionCese
    {
        $datos = ['dias_vacaciones_gozados' => 0, 'decision_quinta_boleta_cese' => 'ajuste_en_liquidacion'];
        $servicio = app(LiquidacionCeseService::class);
        $propuesta = $servicio->propuesta($this->empresa, $this->colaborador->fresh(), $datos);
        $lineas = collect($propuesta['conceptos'])->map(fn ($l) => ['codigo' => $l['codigo'], 'monto' => $l['monto']])->all();
        $liquidacion = $servicio->registrar($this->empresa, $this->colaborador->fresh(), 'asistido', $lineas, 'Decisión validada (prueba)', $this->usuario, $datos);
        if ($aprobar) {
            $liquidacion->update(['estado' => 'aprobada']);
            $servicio->actualizarEstadoQuinta($liquidacion, 'emitido');
        }

        return $liquidacion->fresh('conceptos');
    }

    /** Boleta de agosto calculada, emitida y pagada (base de una complementaria). */
    private function boletaAgostoPagada(): Boleta
    {
        $ciclo = CicloRemunerativo::where('empresa_id', $this->empresa->id)->where('nombre', 'Agosto')->firstOrFail();
        app(BoletaService::class)->calcularPlanilla($this->empresa, $ciclo, $this->usuario);
        $boleta = Boleta::where('ciclo_id', $ciclo->id)->where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->firstOrFail();
        $boleta->update(['estado' => 'pagada']);
        $ciclo->update(['estado' => 'pagado']);
        app(AjusteFinalQuintaCese::class)->actualizarEstado($this->colaborador->fresh(), QuintaAjusteCese::BOLETA, $boleta->id, 'emitido');

        return $boleta->fresh('conceptos.concepto');
    }

    private function boletaDe(string $ciclo): Boleta
    {
        return Boleta::where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)
            ->whereHas('ciclo', fn ($q) => $q->where('nombre', $ciclo))->with('conceptos.concepto')->firstOrFail();
    }

    private function complementariaConBono(Boleta $boleta, float $monto = 1000): PlanillaComplementariaDetalle
    {
        $item = PlanillaComplementaria::create(['empresa_id' => $this->empresa->id, 'ciclo_id' => $boleta->ciclo_id, 'nombre' => 'Bono',
            'motivo' => 'Bono de prueba', 'estado' => 'calculada', 'creado_por' => $this->usuario]);
        app(PlanillaComplementariaService::class)->agregarColaboradores($this->empresa, $item, [$boleta->id]);
        $detalle = PlanillaComplementariaDetalle::where('planilla_complementaria_id', $item->id)->firstOrFail();

        return $this->agregarBono($detalle, $monto);
    }

    private function agregarBono(PlanillaComplementariaDetalle $detalle, float $monto = 1000): PlanillaComplementariaDetalle
    {
        $bono = ConceptoRemuneracion::where('codigo', 'BONO_NO_REMUNERATIVO')->firstOrFail();
        app(PlanillaComplementariaService::class)->agregarConcepto($this->empresa, $detalle->fresh(), $bono->id, null, $monto, 'Bono de prueba', $this->usuario);

        return $detalle->fresh();
    }

    private function aprobar(PlanillaComplementariaDetalle $detalle): void
    {
        app(PlanillaComplementariaService::class)->aprobar($this->empresa, $detalle->complementaria->fresh(), $this->usuario);
    }

    private static function quinta(iterable $ingresos, iterable $egresos): float
    {
        $codigo = fn ($l) => is_array($l) ? ($l['codigo'] ?? null) : ($l->codigo ?? $l->concepto?->codigo);
        $monto = fn ($l) => (float) (is_array($l) ? $l['monto'] : $l->monto);

        return round(collect($egresos)->filter(fn ($l) => $codigo($l) === 'RENTA_5TA')->sum($monto)
            - collect($ingresos)->filter(fn ($l) => $codigo($l) === 'DEVOLUCION_RENTA_5TA')->sum($monto), 2);
    }

    private static function quintaBoleta(Boleta $b): float
    {
        return self::quinta($b->conceptos->where('tipo', 'ingreso'), $b->conceptos->where('tipo', 'egreso'));
    }

    /** 5ta que agrega la complementaria frente a la boleta que corrige. */
    private static function quintaComplementaria(PlanillaComplementariaDetalle $d, float $quintaBase): float
    {
        return round(self::quinta($d->calculo_snapshot['ingresos'] ?? [], $d->calculo_snapshot['egresos'] ?? []) - $quintaBase, 2);
    }

    /** Retenido en el año, medido aparte: versión final de cada boleta (última complementaria emitida) + liquidación vigente. */
    private function totalDelAnio(): float
    {
        $total = 0.0;
        foreach (Boleta::where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->with('conceptos.concepto')->get() as $b) {
            $ultima = PlanillaComplementariaDetalle::where('boleta_original_id', $b->id)
                ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['aprobada', 'pagada']))->latest('id')->first();
            $total += $ultima ? self::quinta($ultima->calculo_snapshot['ingresos'] ?? [], $ultima->calculo_snapshot['egresos'] ?? []) : self::quintaBoleta($b);
        }
        $l = LiquidacionCese::where('colaborador_id', $this->colaborador->id)->where('es_version_vigente', true)->where('estado', '!=', 'anulada')->with('conceptos')->first();

        return round($total + ($l ? self::quinta($l->conceptos->where('tipo', 'ingreso'), $l->conceptos->where('tipo', 'egreso')) : 0), 2);
    }

    private function registro(): QuintaAjusteCese
    {
        $this->assertSame(1, QuintaAjusteCese::where('colaborador_id', $this->colaborador->id)->count());

        return QuintaAjusteCese::where('colaborador_id', $this->colaborador->id)->firstOrFail();
    }

    /** Lo registrado en el ajuste concilia con los documentos vigentes: retenciones previas + aplicaciones = total del año. */
    private function assertRegistroConcilia(float $retencionesPrevias): void
    {
        $aplicado = collect($this->registro()->aplicaciones)->sum(fn ($a) => (float) $a['monto']);
        $this->assertEqualsWithDelta($this->totalDelAnio(), round($retencionesPrevias + $aplicado, 2), 0.001);
    }

    public function test_regresion_sin_complementaria_el_ano_suma_6115_33(): void
    {
        $this->preparar();
        $this->liquidar();
        $this->boletaAgostoPagada();
        $this->assertSame(self::IMPUESTO_SIN_BONO, $this->totalDelAnio());
    }

    public function test_complementaria_gravada_despues_del_ajuste_concilia_solo_la_renta_nueva(): void
    {
        $this->preparar();
        $this->assertSame(1215.33, self::quinta([], $this->liquidar()->conceptos));
        $this->assertSame(700.0, self::quintaBoleta($this->boletaAgostoPagada()));

        $julio = $this->boletaDe('M7');
        $detalle = $this->complementariaConBono($julio);
        // Antes: reproyectaba julio con la renta neta anual como base mensual (1368.89).
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 600));
        $this->assertSame(860.0, (float) $detalle->diferencia_neta);
        $this->assertSame(600.0, self::quintaBoleta($julio->fresh('conceptos.concepto')), 'La boleta emitida no se modifica.');
        $this->aprobar($detalle);

        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $registro = $this->registro();
        $this->assertEquals(140.0, $registro->aplicaciones[AjusteFinalQuintaCese::claveComplementaria($julio->id)]['monto']);
        $this->assertSame('emitido', $registro->aplicaciones[AjusteFinalQuintaCese::claveComplementaria($julio->id)]['estado']);
        $this->assertRegistroConcilia(4200);
    }

    public function test_complementaria_gravada_antes_del_ajuste_difiere_y_la_liquidacion_la_cuenta(): void
    {
        $this->preparar();
        $julio = $this->boletaDe('M7');
        $detalle = $this->complementariaConBono($julio);
        // Sin documento del cese todavía: no retiene; muestra dónde se aplicará.
        $this->assertSame(0.0, self::quintaComplementaria($detalle, 600));
        $this->assertTrue($detalle->calculo_snapshot['quinta_cese']['difiere']);
        $this->assertStringContainsString('se aplicará en la boleta del mes del cese', $detalle->calculo_snapshot['quinta_cese']['mensaje']);
        $this->aprobar($detalle);

        // La liquidación cuenta el bono: 1215.33 + 140.
        $this->assertSame(1355.33, self::quinta([], $this->liquidar()->conceptos));
        $this->assertSame(700.0, self::quintaBoleta($this->boletaAgostoPagada()));
        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertRegistroConcilia(4200);
    }

    public function test_complementaria_sobre_la_boleta_del_cese_conserva_su_ajuste_y_agrega_la_diferencia(): void
    {
        $this->preparar();
        $this->liquidar();
        $agosto = $this->boletaAgostoPagada();
        $detalle = $this->complementariaConBono($agosto);
        // Antes: reemplazaba el ajuste de la boleta por una proyección (12164.33).
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 700));
        $lineas = collect($detalle->calculo_snapshot['egresos'])->where('codigo', 'RENTA_5TA');
        $this->assertSame([700.0, 140.0], $lineas->pluck('monto')->map(fn ($m) => (float) $m)->values()->all());
        $this->aprobar($detalle);

        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertRegistroConcilia(4200);
    }

    public function test_recalculo_repetido_de_la_misma_complementaria_no_acumula(): void
    {
        $this->preparar();
        $this->liquidar();
        $this->boletaAgostoPagada();
        $julio = $this->boletaDe('M7');
        $detalle = $this->complementariaConBono($julio);
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 600));

        $linea = collect($detalle->calculo_snapshot['ingresos'])->firstWhere('codigo', 'BONO_NO_REMUNERATIVO');
        app(PlanillaComplementariaService::class)->eliminarConcepto($this->empresa, $detalle->fresh(), $linea['id']);
        $this->assertSame(0.0, self::quintaComplementaria($detalle->fresh(), 600));
        $detalle = $this->agregarBono($detalle);
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 600));

        $servicio = app(PlanillaComplementariaService::class);
        $this->aprobar($detalle);
        $servicio->reabrir($this->empresa, $detalle->complementaria->fresh(), $this->usuario, 'Revisión');
        // Reabierta deja de contar en el ajuste.
        $this->assertArrayNotHasKey(AjusteFinalQuintaCese::claveComplementaria($julio->id), $this->registro()->aplicaciones);
        $this->aprobar($detalle);
        $servicio->marcarPagada($this->empresa, $detalle->complementaria->fresh(), $this->usuario, 'REF-1');

        $detalle = $detalle->fresh();
        $this->assertSame(1, collect($detalle->calculo_snapshot['egresos'])->filter(fn ($l) => ! empty($l[AjusteFinalQuintaCese::MARCA_LINEA]))->count());
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 600));
        $this->assertSame(2, $this->registro()->aplicaciones[AjusteFinalQuintaCese::claveComplementaria($julio->id)]['version']);
        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertRegistroConcilia(4200);
    }

    public function test_segunda_complementaria_de_la_misma_boleta_reemplaza_la_aplicacion_sin_duplicar(): void
    {
        $this->preparar();
        $this->liquidar();
        $this->boletaAgostoPagada();
        $julio = $this->boletaDe('M7');
        $primera = $this->complementariaConBono($julio);
        $this->aprobar($primera);
        app(PlanillaComplementariaService::class)->marcarPagada($this->empresa, $primera->complementaria->fresh(), $this->usuario, 'REF-1');

        // Otro bono de 500 sobre la versión ya pagada: 500 × 14 % = 70 más.
        $segunda = $this->complementariaConBono($julio, 500);
        $this->assertSame(70.0, round(self::quintaComplementaria($segunda, 600) - 140, 2));
        $this->aprobar($segunda);

        // Independiente: 94966.67 + 500 − 38500 = 56966.67 → 2200 + 29466.67 × 14 % = 6325.33.
        $this->assertSame(6325.33, $this->totalDelAnio());
        $this->assertEquals(210.0, $this->registro()->aplicaciones[AjusteFinalQuintaCese::claveComplementaria($julio->id)]['monto']);
        $this->assertRegistroConcilia(4200);
    }

    public function test_boleta_del_cese_emitida_con_exceso_se_conserva_y_la_complementaria_devuelve(): void
    {
        // Liquidación emitida con 1215.33 y, después, boleta de agosto emitida antes del cambio con la
        // proyección mensual (1298.67): el año quedó con 598.67 retenidos de más (escenario "antes" A).
        $this->preparar();
        $this->assertSame(1215.33, self::quinta([], $this->liquidar()->conceptos));
        CicloRemunerativo::where('empresa_id', $this->empresa->id)->where('nombre', 'Agosto')->delete();
        $this->boletaEmitida($this->colaborador, 8, 5000, 1298.67, 'Agosto');
        $this->assertSame(6714.0, $this->totalDelAnio());
        $agosto = $this->boletaDe('Agosto');
        $detalle = $this->complementariaConBono($agosto);

        // 6255.33 − (4200 + 1215.33 + 1298.67) = −458.67: devolución en la complementaria (art. 42 a).
        $this->assertSame(-458.67, self::quintaComplementaria($detalle, 1298.67));
        $devolucion = collect($detalle->calculo_snapshot['ingresos'])->firstWhere('codigo', 'DEVOLUCION_RENTA_5TA');
        $this->assertSame(458.67, (float) $devolucion['monto']);
        $this->assertSame(1458.67, (float) $detalle->diferencia_neta);
        $this->assertSame(1298.67, self::quintaBoleta($agosto->fresh('conceptos.concepto')), 'La boleta emitida no se modifica.');
        $this->aprobar($detalle);

        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertEquals(-458.67, $this->registro()->aplicaciones[AjusteFinalQuintaCese::claveComplementaria($agosto->id)]['monto']);
    }

    public function test_aprobacion_recalcula_si_otro_documento_cambio_el_ajuste_desde_el_borrador(): void
    {
        $this->preparar();
        $julio = $this->boletaDe('M7');
        // Borrador antes del cese: difiere (0).
        $detalle = $this->complementariaConBono($julio);
        $this->assertSame(0.0, self::quintaComplementaria($detalle, 600));
        // Mientras tanto se emiten la liquidación (sin contar el borrador) y la boleta del cese.
        $this->assertSame(1215.33, self::quinta([], $this->liquidar()->conceptos));
        $this->boletaAgostoPagada();

        try {
            $this->aprobar($detalle);
            $this->fail('Aprobar con la 5ta desactualizada debe pedir revisión.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('La renta de 5ta del cese cambió', collect($e->errors())->flatten()->first());
        }
        $this->assertSame('calculada', $detalle->complementaria->fresh()->estado);
        $this->assertSame(140.0, self::quintaComplementaria($detalle->fresh(), 600), 'El borrador queda con el importe actualizado.');

        $this->aprobar($detalle);
        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertRegistroConcilia(4200);
    }

    public function test_recalculo_completo_de_la_boleta_del_cese_con_reintegro_de_sueldo(): void
    {
        $this->preparar();
        $this->liquidar();
        $agosto = $this->boletaAgostoPagada();
        // Reintegro: el sueldo de agosto debía ser 12000 (15 días = 6000, 1000 más de lo pagado).
        $this->colaborador->remuneraciones()->create(['salario' => 12000, 'moneda_salario' => 'PEN', 'periodicidad_pago' => 'mensual', 'vigencia_desde' => '2026-08-01']);

        $item = app(PlanillaComplementariaService::class)->crear($this->empresa, $agosto->ciclo, [$agosto->id], 'Reintegro de sueldo', $this->usuario);
        $detalle = $item->detalles->first()->fresh();
        // Mismo 14 % marginal sobre los 1000 nuevos; la retención de la boleta (700) se conserva.
        $this->assertSame(140.0, self::quintaComplementaria($detalle, 700));
        $this->assertContains(700.0, collect($detalle->calculo_snapshot['egresos'])->where('codigo', 'RENTA_5TA')->pluck('monto')->map(fn ($m) => (float) $m)->all());
        $this->aprobar($detalle);

        $this->assertSame(self::IMPUESTO_CON_BONO, $this->totalDelAnio());
        $this->assertRegistroConcilia(4200);
    }
}
