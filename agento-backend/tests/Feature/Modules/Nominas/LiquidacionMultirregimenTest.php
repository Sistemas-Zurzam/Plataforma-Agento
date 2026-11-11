<?php

namespace Tests\Feature\Modules\Nominas;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Domain\PlanillaDependienteCalculator;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\VacacionMovimiento;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Services\ColaboradorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

/**
 * Casos reproducibles de la auditoría multirrégimen. Los esperados se
 * calculan a mano en cada comentario (sueldo 3,000 → remuneración diaria 100),
 * nunca con el motor auditado.
 */
class LiquidacionMultirregimenTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function empresa(string $regimen = 'General', array $extra = []): Empresa
    {
        $empresa = Empresa::factory()->create(['regimen_laboral' => $regimen, ...$extra]);
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);

        return $empresa;
    }

    private function pequenaInscrita(string $desde = '2010-01-01'): Empresa
    {
        return $this->empresa('Pequeña Empresa', ['inscrita_remype' => true, 'fecha_inscripcion_remype' => $desde, 'numero_registro_remype' => 'R-1']);
    }

    private function cesado(Empresa $empresa, ?string $regimen, string $ingreso, string $cese, float $sueldo = 3000, array $extra = [], ?string $causal = null): Colaborador
    {
        $colaborador = $this->crearColaborador($empresa, ['fecha_ingreso' => $ingreso, 'regimen_laboral' => $regimen, ...$extra]);
        $colaborador->remuneraciones()->update(['salario' => $sueldo, 'vigencia_desde' => $ingreso]);

        return app(ColaboradorService::class)->cesar($empresa, $colaborador, $cese, 'Texto libre', $causal)->fresh();
    }

    private function condicion(Colaborador $c, ?string $regimen, string $desde): void
    {
        ColaboradorCondicionLaboral::create(['colaborador_id' => $c->id, 'regimen_laboral' => $regimen, 'tipo_contrato' => $c->tipo_contrato, 'vigencia_desde' => $desde]);
    }

    private function propuesta(Colaborador $c, array $datos = []): array
    {
        return app(LiquidacionCeseService::class)->propuesta($c->empresa, $c, $datos);
    }

    private static function m(array $p, string $codigo): float
    {
        return round((float) collect($p['conceptos'])->where('codigo', $codigo)->sum('monto'), 2);
    }

    private static function validacion(array $p, string $clave): ?array
    {
        return collect($p['validaciones'])->firstWhere('clave', $clave);
    }

    private function gratPagada(Colaborador $c, string $tipo, int $anio, float $bruta, string $pagadoAt): void
    {
        $b = BeneficioSocial::firstOrCreate(['empresa_id' => $c->empresa_id, 'tipo' => $tipo, 'anio' => $anio, 'version' => 1], [
            'es_version_vigente' => true, 'total_colaboradores' => 1, 'total_bruto' => $bruta, 'total_neto' => $bruta,
            'estado' => 'pagado', 'calculado_at' => $pagadoAt, 'pagado_at' => $pagadoAt]);
        BeneficioSocialDetalle::create(['beneficio_social_id' => $b->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 3000,
            'meses' => 6, 'bruta' => $bruta, 'bonificacion_extraordinaria' => 0, 'neta' => $bruta]);
    }

    private function boleta(Colaborador $c, string $mes, array $ingresos, array $egresos = []): void
    {
        $inicio = Carbon::parse($mes.'-01');
        $ciclo = CicloRemunerativo::where('empresa_id', $c->empresa_id)->whereDate('fecha_inicio', $inicio->toDateString())->first()
            ?? CicloRemunerativo::create(['empresa_id' => $c->empresa_id, 'fecha_inicio' => $inicio->toDateString(), 'nombre' => $mes,
                'fecha_fin' => $inicio->copy()->endOfMonth()->toDateString(), 'fecha_corte_asistencia' => $inicio->copy()->endOfMonth()->toDateString(),
                'fecha_pago' => $inicio->copy()->endOfMonth()->toDateString(), 'estado' => 'pagado']);
        $boleta = Boleta::create(['empresa_id' => $c->empresa_id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $c->id,
            'regimen_laboral_snapshot' => $c->regimen_laboral ?: 'General', 'sueldo_basico_snapshot' => 3000, 'dias_pagados' => 30,
            'total_ingresos' => array_sum($ingresos), 'total_egresos' => array_sum($egresos), 'total_aportaciones' => 0,
            'neto_a_pagar' => array_sum($ingresos) - array_sum($egresos), 'estado' => 'pagada', 'es_version_vigente' => true,
            'snapshot_parametros_version' => 't', 'snapshot_reglas_version' => 't', 'calculado_at' => $inicio->copy()->endOfMonth()]);
        foreach ([['ingreso', $ingresos], ['egreso', $egresos]] as [$tipo, $lineas]) {
            foreach ($lineas as $codigo => $monto) {
                $boleta->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id, 'tipo' => $tipo,
                    'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso', 'monto' => $monto, 'formula_texto' => 'prueba']);
            }
        }
    }

    public function test_r2_record_ganado_en_general_conserva_30_dias_aunque_el_vinculo_termine_en_pequena(): void
    {
        $empresa = $this->pequenaInscrita();
        $c = $this->cesado($empresa, 'Pequeña Empresa', '2024-03-01', '2026-08-15');
        $this->condicion($c, 'General', '2024-03-01');
        $this->condicion($c, 'Pequeña Empresa', '2026-01-01');

        $p = $this->propuesta($c, ['dias_vacaciones_gozados' => 0, 'gratificacion_percibida_cts' => 1500]);

        $this->assertSame([['General', '2024-03-01', '2025-12-31'], ['Pequeña Empresa', '2026-01-01', '2026-08-15']],
            collect($p['regimen_tramos'])->map(fn ($t) => [$t['regimen'], $t['desde'], $t['hasta']])->all());
        $this->assertFalse($p['requiere_validacion']);
        // Récord 1 (01/03/2024–28/02/2025) íntegro en General: 30 días × 100, vencido.
        $this->assertSame(3000.0, self::m($p, 'INDEMNIZACION_VACACIONAL'));
        // Récord 1 (30 d) + récord 2: 300 d General (30/360) + 60 d Pequeña (15/360) = 25 + 2.5 → 57.5 d × 100.
        $this->assertSame(5750.0, self::m($p, 'VACACIONES_PENDIENTES'));
        // Trunco 01/03–15/08/2026 en Pequeña: 165 d × 15/360 = 6.875 d × 100.
        $this->assertSame(687.5, self::m($p, 'VACACIONES_TRUNCAS'));
        // Gratificación: julio en Pequeña (½): 3000/6 × 0.5 = 250; bonificación 9 % = 22.50.
        $this->assertSame(250.0, self::m($p, 'GRATIFICACION_TRUNCA'));
        $this->assertSame(22.5, self::m($p, 'BONIFICACION_EXTRAORDINARIA_TRUNCA'));
        // CTS 01/05–15/08 (105 d) en Pequeña: (3000 + 1500/6) / 360 × 105 × 0.5 = 473.96.
        $this->assertSame(473.96, self::m($p, 'CTS_TRUNCA'));
    }

    public function test_regimen_vacio_queda_por_validar_se_guarda_en_borrador_y_se_resuelve_con_decision(): void
    {
        $empresa = $this->empresa();
        $c = $this->cesado($empresa, null, '2025-02-01', '2026-08-15');
        $this->condicion($c, null, '2025-02-01');
        $servicio = app(LiquidacionCeseService::class);
        $usuario = User::factory()->create(['empresa_id' => $empresa->id])->id;

        $p = $this->propuesta($c);
        $this->assertTrue($p['requiere_validacion']);
        $this->assertFalse(self::validacion($p, 'decision_regimen_vacio')['resuelta']);

        $lineas = collect($p['conceptos'])->map(fn ($l) => ['codigo' => $l['codigo'], 'monto' => $l['monto']])->all();
        $borrador = $servicio->registrar($empresa, $c, 'asistido', $lineas, null, $usuario);
        $this->assertSame('calculada', $borrador->estado);
        $this->assertNotEmpty($servicio->validacionesPendientes($borrador));

        $resuelta = $this->propuesta($c, ['decision_regimen_vacio' => 'General']);
        $this->assertTrue(self::validacion($resuelta, 'decision_regimen_vacio')['resuelta']);
        $lineas = collect($resuelta['conceptos'])->map(fn ($l) => ['codigo' => $l['codigo'], 'monto' => $l['monto']])->all();
        $final = $servicio->registrar($empresa, $c, 'asistido', $lineas, 'Contrato firmado en régimen general', $usuario, ['decision_regimen_vacio' => 'General']);
        $this->assertSame([], $servicio->validacionesPendientes($final));
        $this->assertSame('General', $final->datos_calculo['decision_regimen_vacio']['valor']);
    }

    public function test_vinculo_anterior_a_remype_y_empresa_no_inscrita_quedan_por_validar_con_cada_opcion_cuantificada(): void
    {
        $micro = $this->empresa('Micro Empresa', ['inscrita_remype' => true, 'fecha_inscripcion_remype' => '2025-01-01', 'numero_registro_remype' => 'R-2']);
        $c = $this->cesado($micro, 'Micro Empresa', '2024-03-01', '2026-08-15');
        $datos = ['dias_vacaciones_gozados' => 0];

        $p = $this->propuesta($c, $datos);
        $this->assertTrue($p['requiere_validacion']);
        $this->assertNotNull(self::validacion($p, 'decision_mype_anterior_remype'));
        $this->assertSame(0.0, self::m($p, 'CTS_TRUNCA'));

        // Régimen general por todo el vínculo: grat 500 + bonif 45; CTS 3000 × 105/360 = 875;
        // vacaciones 1375 + 6000 + 3000 = 10375.
        $general = $this->propuesta($c, [...$datos, 'decision_mype_anterior_remype' => 'general_todo_el_vinculo']);
        $this->assertSame(545.0, self::m($general, 'GRATIFICACION_TRUNCA') + self::m($general, 'BONIFICACION_EXTRAORDINARIA_TRUNCA'));
        $this->assertSame(875.0, self::m($general, 'CTS_TRUNCA'));
        $this->assertSame(10375.0, self::m($general, 'VACACIONES_TRUNCAS') + self::m($general, 'VACACIONES_PENDIENTES') + self::m($general, 'INDEMNIZACION_VACACIONAL'));

        // General hasta la inscripción: récord 1 = 300 d General (25) + 60 d Micro (2.5) = 27.5 d vencidos → 2750.
        $mixto = $this->propuesta($c, [...$datos, 'decision_mype_anterior_remype' => 'general_hasta_inscripcion']);
        $this->assertSame(2750.0, self::m($mixto, 'INDEMNIZACION_VACACIONAL'));

        // Empresa sin inscripción REMYPE: aplicando el general, CTS 875 + grat 500 + bonif 45 = 1420.
        $noInscrita = $this->cesado($this->empresa('Micro Empresa', ['inscrita_remype' => false]), 'Micro Empresa', '2024-03-01', '2026-08-15');
        $this->assertNotNull(self::validacion($this->propuesta($noInscrita, $datos), 'decision_mype_sin_remype'));
        $aplicado = $this->propuesta($noInscrita, [...$datos, 'decision_mype_sin_remype' => 'aplicar_general']);
        $this->assertSame(1420.0, self::m($aplicado, 'CTS_TRUNCA') + self::m($aplicado, 'GRATIFICACION_TRUNCA') + self::m($aplicado, 'BONIFICACION_EXTRAORDINARIA_TRUNCA'));
    }

    public function test_historial_incompleto_usa_el_primer_regimen_conocido_con_alerta(): void
    {
        $c = $this->cesado($this->empresa(), 'General', '2024-03-01', '2026-08-15');
        $this->condicion($c, 'General', '2025-06-01');
        $p = $this->propuesta($c);
        $this->assertSame('supuesto', $p['regimen_tramos'][0]['origen']);
        $this->assertFalse($p['requiere_validacion']);
        $this->assertTrue(collect($p['alertas'])->contains(fn ($a) => str_contains($a, 'empieza el 01/06/2025')));
    }

    public function test_dos_vinculos_en_una_empresa_y_una_persona_en_dos_empresas(): void
    {
        $a = $this->pequenaInscrita();
        $general = $this->cesado($a, 'General', '2024-03-01', '2026-08-15', 3000, ['numero_documento' => '44556677']);
        $pequena = $this->cesado($a, 'Pequeña Empresa', '2024-03-01', '2026-08-15');
        // Gratificación trunca de julio: 3000/6 = 500 en General; ×0.5 = 250 en Pequeña.
        $this->assertSame(500.0, self::m($this->propuesta($general), 'GRATIFICACION_TRUNCA'));
        $this->assertSame(250.0, self::m($this->propuesta($pequena), 'GRATIFICACION_TRUNCA'));

        // La misma persona (mismo DNI) en otra empresa, con pagos propios en B.
        $b = $this->empresa();
        $enB = $this->crearColaborador($b, ['fecha_ingreso' => '2024-03-01', 'regimen_laboral' => 'General', 'numero_documento' => '44556677']);
        $this->gratPagada($enB, 'gratificacion_julio', 2026, 3000, '2026-07-15 10:00:00');
        VacacionMovimiento::create(['empresa_id' => $b->id, 'colaborador_id' => $enB->id, 'fecha' => '2026-02-01', 'tipo' => 'ajuste', 'dias' => 20, 'descripcion' => 'B']);
        foreach (['2026-04', '2026-05', '2026-06'] as $mes) {
            $this->boleta($enB, $mes, ['SUELDO_BASICO' => 3000, 'HE_25' => 600]);
        }
        $p = $this->propuesta($general);
        $this->assertSame(0.0, (float) $p['datos_calculo']['gratificacion_percibida_cts']['valor']);
        $this->assertSame(0.0, (float) $p['datos_calculo']['dias_vacaciones_gozados']['valor']);
        $this->assertSame(0.0, collect($p['bases_variables'])->sum('total'));
    }

    public function test_q2_cese_en_la_primera_quincena_de_julio_sin_gratificacion_pagada(): void
    {
        $empresa = $this->empresa();
        $sinPago = $this->propuesta($this->cesado($empresa, 'General', '2024-01-01', '2026-07-05'));
        // Ley 27735 art. 7: sin vínculo el 15/07 → proporcional a ene–jun: 3000/6 × 6 = 3000; bonificación 270.
        $this->assertSame(3000.0, self::m($sinPago, 'GRATIFICACION_SEMESTRE_ANTERIOR'));
        $this->assertSame(270.0, self::m($sinPago, 'BONIFICACION_EXTRAORDINARIA_SEMESTRE_ANTERIOR'));
        $this->assertSame(0.0, self::m($sinPago, 'GRATIFICACION_TRUNCA'));

        $conPago = $this->cesado($empresa, 'General', '2024-01-01', '2026-07-05');
        $this->gratPagada($conPago, 'gratificacion_julio', 2026, 3000, '2026-07-03 10:00:00');
        $this->assertSame(0.0, self::m($this->propuesta($conPago->fresh()), 'GRATIFICACION_SEMESTRE_ANTERIOR'));
    }

    public function test_q9_vacaciones_truncas_exigen_un_mes_de_servicios(): void
    {
        $empresa = $this->empresa();
        // D.S. 012-92-TR art. 23: 29 días → 0; mes completo → 3000 × 30/360 = 250.
        $this->assertSame(0.0, self::m($this->propuesta($this->cesado($empresa, 'General', '2026-08-01', '2026-08-29')), 'VACACIONES_TRUNCAS'));
        $this->assertSame(250.0, self::m($this->propuesta($this->cesado($empresa, 'General', '2026-08-01', '2026-08-31')), 'VACACIONES_TRUNCAS'));
    }

    public function test_gratificacion_trunca_usa_la_remuneracion_del_mes_anterior_al_cese(): void
    {
        $c = $this->cesado($this->empresa(), 'General', '2025-01-01', '2026-08-20');
        ColaboradorRemuneracion::create(['colaborador_id' => $c->id, 'salario' => 3500, 'moneda_salario' => 'PEN', 'periodicidad_pago' => 'mensual', 'vigencia_desde' => '2026-08-10']);
        // D.S. 005-2002-TR num. 5.3: base de julio (3000) / 6 × 1 mes = 500 (antes 583.33 con 3500).
        $this->assertSame(500.0, self::m($this->propuesta($c->fresh()), 'GRATIFICACION_TRUNCA'));
    }

    public function test_r6_variables_por_beneficio(): void
    {
        $empresa = $this->empresa();
        $tres = $this->cesado($empresa, 'General', '2025-01-01', '2026-10-31');
        $this->gratPagada($tres, 'gratificacion_julio', 2026, 3000, '2026-07-15 10:00:00');
        foreach (['2026-05' => 120, '2026-07' => 180, '2026-09' => 300] as $mes => $he) {
            $this->boleta($tres, $mes, ['SUELDO_BASICO' => 3000, 'HE_25' => $he]);
        }
        $p = $this->propuesta($tres->fresh());
        // CTS mayo–octubre: (3000 + 600/6 + 3000/6) / 360 × 180 = 1800.
        $this->assertSame(1800.0, self::m($p, 'CTS_TRUNCA'));
        $cts = collect($p['bases_variables'])->firstWhere('beneficio', 'CTS trunca');
        $this->assertSame(100.0, $cts['total']);
        $this->assertSame(3, $cts['componentes'][0]['meses_con_pago']);

        $dos = $this->cesado($empresa, 'General', '2025-01-01', '2026-10-31');
        $this->gratPagada($dos, 'gratificacion_julio', 2026, 3000, '2026-07-15 10:00:00');
        $this->boleta($dos, '2026-05', ['SUELDO_BASICO' => 3000, 'HE_25' => 300]);
        $this->boleta($dos, '2026-07', ['SUELDO_BASICO' => 3000, 'HE_25' => 300]);
        // Dos meses: no regular → (3000 + 500) / 2 = 1750.
        $this->assertSame(1750.0, self::m($this->propuesta($dos->fresh()), 'CTS_TRUNCA'));

        // Comisión como remuneración principal, tramo 01/05–15/07 (75 días comerciales).
        $comi = $this->cesado($empresa, 'General', '2026-05-01', '2026-07-15', 0.01);
        foreach (['2026-05' => 1500, '2026-06' => 1800, '2026-07' => 900] as $mes => $monto) {
            $this->boleta($comi, $mes, ['COMISION' => $monto]);
        }
        $complementaria = $this->propuesta($comi->fresh());
        $principal = $this->propuesta($comi->fresh(), ['comision_principal' => true]);
        // Complementaria: 3 meses con pago → 4200/6 = 700 de base; principal: 4200/75 × 30 = 1680 de base.
        $this->assertSame(700.0, collect($complementaria['bases_variables'])->firstWhere('beneficio', 'CTS trunca')['total']);
        $this->assertSame(1680.0, collect($principal['bases_variables'])->firstWhere('beneficio', 'CTS trunca')['total']);
        // CTS 01/05–15/07 = 75 días comerciales: (0.01 + 1680) / 360 × 75 = 350.00.
        $this->assertSame(350.0, self::m($principal, 'CTS_TRUNCA'));
    }

    public function test_r3_tope_de_cts_en_pequena_empresa_queda_pendiente_con_ambas_opciones_cuantificadas(): void
    {
        $empresa = $this->pequenaInscrita('2010-01-01');
        $c = $this->cesado($empresa, 'Pequeña Empresa', '2015-05-01', '2026-08-15');
        $this->condicion($c, 'Pequeña Empresa', '2015-05-01');

        $p = $this->propuesta($c, ['dias_vacaciones_gozados' => 300]);
        $tope = self::validacion($p, 'decision_tope_cts_pequena');
        $this->assertNotNull($tope);
        $this->assertTrue($p['requiere_validacion']);
        // Sin aplicar: 01/05–15/08 = 105 d × 3000/360 × 0.5 = 437.50; con tope (acumulado teórico > 90): 0.
        $this->assertSame(437.5, self::m($p, 'CTS_TRUNCA'));
        $this->assertSame(0.0, self::m($this->propuesta($c, ['dias_vacaciones_gozados' => 300, 'decision_tope_cts_pequena' => 'aplicar_tope_teorico']), 'CTS_TRUNCA'));
        $this->assertSame(-437.5, collect($tope['diferencias'])->firstWhere('opcion', 'aplicar_tope_teorico')['diferencia_neto']);
    }

    public function test_cts_de_semestre_cerrado_sin_deposito_se_muestra_aparte_y_no_duplica_el_neto(): void
    {
        $c = $this->cesado($this->empresa(), 'General', '2025-01-01', '2026-11-05');
        $this->gratPagada($c, 'gratificacion_julio', 2026, 3000, '2026-07-15 10:00:00');

        $p = $this->propuesta($c->fresh());
        $this->assertNotNull(self::validacion($p, 'decision_cts_semestre_cerrado'));
        $this->assertSame(0.0, self::m($p, 'CTS_SEMESTRE_CERRADO'));
        // Trunca 01–05/11: 3000 / 360 × 5 = 41.67 (sin sexto: la gratificación de diciembre aún no se percibe).
        $this->assertSame(41.67, self::m($p, 'CTS_TRUNCA'));

        // Pago directo: semestre mayo–octubre (3000 + 3000/6) / 360 × 180 = 1750.
        $directo = $this->propuesta($c->fresh(), ['decision_cts_semestre_cerrado' => 'pago_directo']);
        $this->assertSame(1750.0, self::m($directo, 'CTS_SEMESTRE_CERRADO'));
        $deposito = $this->propuesta($c->fresh(), ['decision_cts_semestre_cerrado' => 'deposito_en_entidad']);
        $this->assertSame(0.0, self::m($deposito, 'CTS_SEMESTRE_CERRADO'));
        $this->assertSame(round($directo['neto_pagar'] - 1750, 2), $deposito['neto_pagar']);

        // Con depósito registrado no hay validación.
        BeneficioSocialDetalle::create(['beneficio_social_id' => BeneficioSocial::create(['empresa_id' => $c->empresa_id, 'tipo' => 'cts_noviembre', 'anio' => 2026,
            'version' => 1, 'es_version_vigente' => true, 'total_colaboradores' => 1, 'total_bruto' => 1750, 'total_neto' => 1750, 'estado' => 'pagado',
            'calculado_at' => '2026-11-02', 'pagado_at' => '2026-11-03'])->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 3000, 'meses' => 6, 'bruta' => 1750, 'bonificacion_extraordinaria' => 0, 'neta' => 1750]);
        $this->assertNull(self::validacion($this->propuesta($c->fresh()), 'decision_cts_semestre_cerrado'));
    }

    public function test_indemnizacion_por_despido_segun_causal_regimen_y_contrato(): void
    {
        $general = $this->empresa();
        // General, indeterminado, 2a 5m 15d = 885 días comerciales: 1.5 × 3000 × 885/360 = 11062.50.
        $this->assertSame(11062.5, self::m($this->propuesta($this->cesado($general, 'General', '2024-03-01', '2026-08-15', 3000, [], 'despido_arbitrario')), 'INDEMNIZACION_DESPIDO'));
        $this->assertSame(0.0, self::m($this->propuesta($this->cesado($general, 'General', '2024-03-01', '2026-08-15', 3000, [], 'renuncia')), 'INDEMNIZACION_DESPIDO'));
        $this->assertSame(0.0, self::m($this->propuesta($this->cesado($general, 'General', '2026-07-01', '2026-08-15', 3000, [], 'despido_arbitrario')), 'INDEMNIZACION_DESPIDO'));
        // Tope: 16 años → 12 × 3000 = 36000.
        $this->assertSame(36000.0, self::m($this->propuesta($this->cesado($general, 'General', '2010-01-01', '2026-08-15', 3000, [], 'despido_arbitrario')), 'INDEMNIZACION_DESPIDO'));
        // Plazo fijo hasta el 15/12: 120 d / 30 = 4 meses × 1.5 × 3000 = 18000.
        $plazo = $this->cesado($general, 'General', '2025-01-01', '2026-08-15', 3000, ['tipo_contrato' => 'plazo_fijo', 'fecha_fin_contrato' => '2026-12-15'], 'despido_arbitrario');
        $this->assertSame(18000.0, self::m($this->propuesta($plazo), 'INDEMNIZACION_DESPIDO'));
        // Causal histórica sin tipificar: no se calcula, pero se puede tipificar en la liquidación.
        $sinCausal = $this->cesado($general, 'General', '2024-03-01', '2026-08-15');
        $this->assertSame(0.0, self::m($this->propuesta($sinCausal), 'INDEMNIZACION_DESPIDO'));
        $this->assertSame(11062.5, self::m($this->propuesta($sinCausal, ['causal_cese' => 'despido_arbitrario']), 'INDEMNIZACION_DESPIDO'));

        // Pequeña: 20 × 100 × (2 + 5/12) = 4833.33; Micro: 10 × 100 × 2.41667 = 2416.67.
        $this->assertSame(4833.33, self::m($this->propuesta($this->cesado($this->pequenaInscrita(), 'Pequeña Empresa', '2024-03-01', '2026-08-15', 3000, [], 'despido_arbitrario')), 'INDEMNIZACION_DESPIDO'));
        $micro = $this->empresa('Micro Empresa', ['inscrita_remype' => true, 'fecha_inscripcion_remype' => '2010-01-01', 'numero_registro_remype' => 'R-9']);
        $this->assertSame(2416.67, self::m($this->propuesta($this->cesado($micro, 'Micro Empresa', '2024-03-01', '2026-08-15', 3000, [], 'despido_arbitrario')), 'INDEMNIZACION_DESPIDO'));
    }

    public function test_aportes_del_empleador_eps_y_quinta_categoria(): void
    {
        $empresa = $this->empresa();
        $c = $this->cesado($empresa, 'General', '2026-01-01', '2026-08-15', 10000);
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $mes) {
            $this->boleta($c, $mes, ['SUELDO_BASICO' => 10000], ['RENTA_5TA' => 300]);
        }
        $p = $this->propuesta($c->fresh(), ['dias_vacaciones_gozados' => 0]);
        $vacaciones = self::m($p, 'VACACIONES_TRUNCAS') + self::m($p, 'VACACIONES_PENDIENTES');
        $this->assertSame(round($vacaciones * 0.09, 2), self::m($p, 'ESSALUD'));
        $this->assertEquals(round($p['total_ingresos'] - $p['total_egresos'], 2), $p['neto_pagar']);
        $this->assertSame(self::m($p, 'ESSALUD'), $p['total_aportaciones']);

        // Quinta: 70000 del año + gravado de la liquidación − 7 UIT (38500), tramos 8/14/17/20/30 %, menos 2100 retenidos.
        $gravado = collect($p['conceptos'])->whereIn('codigo', ['GRATIFICACION_TRUNCA', 'BONIFICACION_EXTRAORDINARIA_TRUNCA', 'VACACIONES_TRUNCAS', 'VACACIONES_PENDIENTES'])->sum('monto');
        $neta = 70000 + $gravado - 38500;
        $impuesto = min($neta, 27500) * 0.08 + max(0, min($neta, 110000) - 27500) * 0.14;
        $this->assertEqualsWithDelta(round(max(0, $impuesto - 2100), 2), self::m($p, 'RENTA_5TA'), 0.01);

        // EPS: EsSalud 6.75 % y bonificación 6.75 % de la gratificación.
        $eps = $this->propuesta($c->fresh(), ['dias_vacaciones_gozados' => 0, 'cobertura_salud' => 'eps']);
        $this->assertSame(round($vacaciones * 0.0675, 2), self::m($eps, 'ESSALUD'));
        $this->assertSame(round(self::m($eps, 'GRATIFICACION_TRUNCA') * 0.0675, 2), self::m($eps, 'BONIFICACION_EXTRAORDINARIA_TRUNCA'));
    }

    public function test_quinta_al_cese_con_gratificacion_pagada_retenciones_previas_y_devolucion(): void
    {
        $escenario = function (float $retencionMensual, bool $boletaDelCese) {
            $c = $this->cesado($this->empresa(), 'General', '2026-01-01', '2026-08-15', 10000);
            foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $mes) {
                $this->boleta($c, $mes, ['SUELDO_BASICO' => 10000], ['RENTA_5TA' => $retencionMensual]);
            }
            if ($boletaDelCese) {
                $this->boleta($c, '2026-08', ['SUELDO_BASICO' => 5000], ['RENTA_5TA' => 600]);
            }
            // Gratificación de julio pagada por Beneficios Sociales: 10000 + 900 de bonificación.
            $b = BeneficioSocial::create(['empresa_id' => $c->empresa_id, 'tipo' => 'gratificacion_julio', 'anio' => 2026, 'version' => 1, 'es_version_vigente' => true,
                'total_colaboradores' => 1, 'total_bruto' => 10000, 'total_neto' => 10900, 'estado' => 'pagado', 'calculado_at' => '2026-07-10', 'pagado_at' => '2026-07-15']);
            BeneficioSocialDetalle::create(['beneficio_social_id' => $b->id, 'colaborador_id' => $c->id, 'sueldo_basico' => 10000, 'meses' => 6,
                'bruta' => 10000, 'bonificacion_extraordinaria' => 900, 'neta' => 10900]);

            return $this->propuesta($c->fresh(), ['dias_vacaciones_gozados' => 0]);
        };
        // Gravado en la liquidación: grat. trunca 1666.67 + bonif. 150 + vac. truncas 6250 = 8066.67.
        // Renta: 70000 + 10900 + 8066.67 − 38500 = 50466.67 → 2200 + 22966.67 × 14 % = 5415.33.
        $retencion = $escenario(600, false);
        $this->assertSame(1215.33, self::m($retencion, 'RENTA_5TA')); // 5415.33 − 4200
        $this->assertNotNull(self::validacion($retencion, 'decision_quinta_boleta_cese'));
        $this->assertSame(0.0, self::m($this->propuesta(Colaborador::latest('id')->first(), ['dias_vacaciones_gozados' => 0, 'decision_quinta_boleta_cese' => 'ajuste_en_boleta_del_cese']), 'RENTA_5TA'));

        // Retenido de más (6300 > 5415.33): se devuelve el exceso (Reglamento LIR art. 42 a).
        $devolucion = $escenario(900, false);
        $this->assertSame(884.67, self::m($devolucion, 'DEVOLUCION_RENTA_5TA'));
        $this->assertSame(0.0, self::m($devolucion, 'RENTA_5TA'));

        // Con la boleta de agosto (5000, retención 600): 55466.67 → 6115.33 − 4800 = 1315.33, sin validación.
        $conBoleta = $escenario(600, true);
        $this->assertSame(1315.33, self::m($conBoleta, 'RENTA_5TA'));
        $this->assertNull(self::validacion($conBoleta, 'decision_quinta_boleta_cese'));
    }

    public function test_valor_hora_extra_con_asignacion_familiar_y_jornada_real(): void
    {
        $param = ParametrosVigentesResolver::paraRegimen($this->empresa(), 'General', '2026-08-01');
        $monto = fn (float $af, ?float $jornada) => (new PlanillaDependienteCalculator())->calcularHorasExtra(3000, 2, 0, 0, $param, $af, $jornada)[0]['monto'];
        // 2 h al 25 %: 3000/30/8 × 1.25 × 2 = 31.25; con AF 113: 3113/240 × 2.5 = 32.43; jornada de 6 h: 3000/180 × 2.5 = 41.67.
        $this->assertSame(31.25, $monto(0, 8));
        $this->assertSame(32.43, $monto(113, 8));
        $this->assertSame(41.67, $monto(0, 6));
        $this->assertSame(31.25, $monto(0, 9.6));
    }

    public function test_decisiones_pendientes_de_asignacion_familiar_en_micro_y_faltas(): void
    {
        $micro = $this->empresa('Micro Empresa', ['inscrita_remype' => true, 'fecha_inscripcion_remype' => '2010-01-01', 'numero_registro_remype' => 'R-7']);
        $c = $this->cesado($micro, 'Micro Empresa', '2025-03-01', '2026-08-15', 3000, ['tiene_hijos_asignacion_familiar' => true]);
        foreach (['2026-04-07', '2026-04-08'] as $fecha) {
            AsistenciaResultadoDiario::withoutGlobalScopes()->create(['empresa_id' => $micro->id, 'colaborador_id' => $c->id, 'fecha' => $fecha,
                'tipo_dia' => 'laborable', 'estado' => 'falta', 'minutos_programados' => 480, 'minutos_trabajados' => 0, 'procesado_at' => now()]);
        }
        $p = $this->propuesta($c, ['dias_vacaciones_gozados' => 0]);
        $this->assertNotNull(self::validacion($p, 'decision_af_microempresa'));
        $this->assertNotNull(self::validacion($p, 'decision_faltas_vacaciones'));
        // Trunco 01/03–15/08 (165 d × 15/360 = 6.875 d) → 687.50; descontando 2 faltas: 163 d → 6.7917 d → 679.17.
        $this->assertSame(687.5, self::m($p, 'VACACIONES_TRUNCAS'));
        $this->assertSame(679.17, self::m($this->propuesta($c, ['dias_vacaciones_gozados' => 0, 'decision_faltas_vacaciones' => 'descontar_dias']), 'VACACIONES_TRUNCAS'));
        // Con AF (113): (3113/30) × 6.875 = 713.40.
        $this->assertSame(713.4, self::m($this->propuesta($c, ['dias_vacaciones_gozados' => 0, 'decision_af_microempresa' => 'incluir_af']), 'VACACIONES_TRUNCAS'));
    }

    public function test_emision_repetida_conserva_snapshot_y_no_duplica(): void
    {
        $empresa = $this->empresa();
        $c = $this->cesado($empresa, 'General', '2024-03-01', '2026-08-15');
        $servicio = app(LiquidacionCeseService::class);
        $usuario = User::factory()->create(['empresa_id' => $empresa->id])->id;
        $p = $servicio->propuesta($empresa, $c);
        $lineas = collect($p['conceptos'])->map(fn ($l) => ['codigo' => $l['codigo'], 'monto' => $l['monto']])->all();

        $v1 = $servicio->registrar($empresa, $c, 'asistido', $lineas, null, $usuario);
        $v2 = $servicio->registrar($empresa, $c, 'asistido', $lineas, null, $usuario);

        $this->assertSame(1, LiquidacionCese::where('colaborador_id', $c->id)->where('es_version_vigente', true)->count());
        $this->assertEquals($p['neto_pagar'], (float) $v2->neto_pagar);
        $this->assertEquals((float) $v1->neto_pagar, (float) $v2->neto_pagar);
        $guardada = $v2->fresh('conceptos');
        $this->assertEquals(round($guardada->conceptos->where('tipo', 'ingreso')->sum('monto') - $guardada->conceptos->where('tipo', 'egreso')->sum('monto'), 2), (float) $guardada->neto_pagar);
        $this->assertSame('General', $guardada->regimen_tramos[0]['regimen']);
        $this->assertStringStartsWith('General:', $guardada->parametros_version);
        $this->assertArrayHasKey('bases', $guardada->bases_variables);
        $this->assertEquals((float) $guardada->conceptos->where('tipo', 'aportacion')->sum('monto'), (float) $guardada->total_aportaciones);
    }
}
