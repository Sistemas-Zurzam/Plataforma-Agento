<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoDefinicionPlame;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;

class BonoAsistenciaComplementariaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    /** @return array{0: Empresa, 1: CicloRemunerativo, 2: User, 3: PlanillaComplementariaService, 4: ConceptoRemuneracion, 5: ConceptoDefinicionPlame} */
    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        ParametrosVigentesResolver::limpiarCache();
        $usuario = User::where('username', 'test.user')->firstOrFail();

        $ciclo = CicloRemunerativo::create([
            'empresa_id' => $empresa->id, 'nombre' => 'Planilla Agosto - 2026',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31',
            'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado',
        ]);

        // BONO_NO_REMUNERATIVO es demasiado genérico para Tabla 22 sin una
        // clasificación PLAME concreta (ver migración 000080 y el mismo
        // criterio que agregarConcepto()/CicloRemunerativoController) — se
        // crea de antemano, como haría RR.HH. antes de usar el bono masivo.
        $concepto = ConceptoRemuneracion::where('codigo', 'BONO_NO_REMUNERATIVO')->firstOrFail();
        $definicion = ConceptoDefinicionPlame::create([
            'concepto_remuneracion_id' => $concepto->id,
            'nombre' => 'Bono por asistencia perfecta',
            'codigo_plame' => '1001',
            'descripcion_sunat' => 'Otros conceptos',
            'activo' => true,
            'creado_por' => $usuario->id,
        ]);

        $service = app(PlanillaComplementariaService::class);

        return [$empresa, $ciclo, $usuario, $service, $concepto, $definicion];
    }

    private function crearColaboradorConBoletaPagada(Empresa $empresa, CicloRemunerativo $ciclo): Boleta
    {
        $colaborador = $this->crearColaborador($empresa, [
            'fecha_ingreso' => '2026-01-01',
            'regimen_laboral' => 'General',
        ]);
        $colaborador->remuneraciones()->update(['salario' => 3000, 'vigencia_desde' => '2026-01-01']);

        $boleta = Boleta::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $colaborador->id,
            'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 3000, 'dias_pagados' => 30,
            'total_ingresos' => 3000, 'total_egresos' => 390, 'total_aportaciones' => 270,
            'neto_a_pagar' => 2610, 'estado' => 'pagada', 'es_version_vigente' => true,
            'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now(),
        ]);
        foreach ([
            ['SUELDO_BASICO', 'ingreso', 3000, 3000, 30],
            ['ONP', 'egreso', 390, 3000, 1],
            ['ESSALUD', 'aportacion', 270, 3000, 1],
        ] as [$codigo, $tipo, $monto, $base, $cantidad]) {
            $conceptoLinea = ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail();
            $boleta->conceptos()->create([
                'concepto_id' => $conceptoLinea->id, 'tipo' => $tipo, 'monto' => $monto,
                'base_utilizada' => $base, 'cantidad' => $cantidad,
                'es_remunerativo_laboral' => $conceptoLinea->es_remunerativo_laboral,
                'afecta_renta_5ta' => $conceptoLinea->afecta_renta_5ta,
            ]);
        }

        return $boleta;
    }

    /** Crea $dias filas 'presente' con fechas distintas dentro de agosto 2026 (rango del ciclo). */
    private function marcarDiasPresente(Empresa $empresa, int $colaboradorId, int $dias): void
    {
        Boleta::where('empresa_id', $empresa->id)->where('colaborador_id', $colaboradorId)->update(['dias_pagados' => $dias]);
        for ($i = 1; $i <= $dias; $i++) {
            AsistenciaResultadoDiario::create([
                'empresa_id' => $empresa->id, 'colaborador_id' => $colaboradorId,
                'fecha' => sprintf('2026-08-%02d', $i), 'tipo_dia' => 'laborable', 'estado' => 'presente',
                'entrada_at' => sprintf('2026-08-%02d 08:00:00', $i),
                'salida_at' => sprintf('2026-08-%02d 17:00:00', $i),
                'minutos_trabajados' => 480,
                'procesado_at' => now(),
            ]);
        }
    }

    public function test_operador_exacto_solo_lista_a_quien_asistio_exactamente_los_dias_indicados(): void
    {
        [$empresa, $ciclo, , $service, $concepto] = $this->escenario();

        $boleta27 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta27->colaborador_id, 27);
        $boleta26 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta26->colaborador_id, 26);
        $boleta28 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta28->colaborador_id, 28);

        $resultado = collect($service->colaboradoresPorAsistencia($empresa, $ciclo, 27, 'exacto', $concepto->id)['colaboradores']);

        // El conteo exacto se filtra a nivel SQL (having dias = 27): quien
        // asistió 26 o 28 días ni siquiera entra al resultado, no aparece
        // "no disponible" — no hay forma de distinguir "no cumple los días"
        // de "cumple pero no disponible" en la forma actual del array.
        $this->assertCount(1, $resultado);
        $fila = $resultado->firstWhere('boleta_id', $boleta27->id);
        $this->assertNotNull($fila);
        $this->assertSame(27, $fila['dias_asistidos']);
        $this->assertTrue($fila['disponible']);
        $this->assertNull($fila['motivo']);
        $this->assertFalse($resultado->contains('boleta_id', $boleta26->id));
        $this->assertFalse($resultado->contains('boleta_id', $boleta28->id));
    }

    public function test_operador_minimo_lista_a_quienes_asistieron_los_dias_o_mas(): void
    {
        [$empresa, $ciclo, , $service, $concepto] = $this->escenario();

        $boleta27 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta27->colaborador_id, 27);
        $boleta26 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta26->colaborador_id, 26);
        $boleta28 = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta28->colaborador_id, 28);

        $resultado = collect($service->colaboradoresPorAsistencia($empresa, $ciclo, 27, 'minimo', $concepto->id)['colaboradores']);

        $this->assertTrue($resultado->contains('boleta_id', $boleta27->id));
        $this->assertTrue($resultado->contains('boleta_id', $boleta28->id));
        $this->assertFalse($resultado->contains('boleta_id', $boleta26->id));
    }

    public function test_aplicar_bono_por_asistencia_crea_complementaria_con_bono_no_remunerativo_y_bonos_masivos(): void
    {
        [$empresa, $ciclo, $usuario, $service, $concepto, $definicion] = $this->escenario();

        $boleta = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta->colaborador_id, 27);

        $item = $service->aplicarBonoPorAsistencia(
            $empresa, $ciclo, [$boleta->id], 27, 'exacto', $concepto->id, $definicion->id, 150.0,
            'Bono por asistencia perfecta - Agosto 2026', $usuario->id,
        );

        $this->assertInstanceOf(PlanillaComplementaria::class, $item);
        $this->assertSame('calculada', $item->estado);
        $this->assertSame($ciclo->id, $item->ciclo_id);
        $this->assertCount(1, $item->detalles);

        $detalle = $item->detalles->first();
        $snapshot = $detalle->calculo_snapshot;

        $lineaBono = collect($snapshot['ingresos'])->firstWhere('codigo', 'BONO_NO_REMUNERATIVO');
        $this->assertNotNull($lineaBono);
        $this->assertEquals(150.0, $lineaBono['monto']);
        $this->assertSame($definicion->id, $lineaBono['concepto_definicion_id']);

        $bonoMasivo = collect($snapshot['bonos_masivos'] ?? [])->first();
        $this->assertNotNull($bonoMasivo);
        $criterioEsperado = "asistencia:exacto:27:{$ciclo->id}:{$concepto->id}";
        $this->assertSame($criterioEsperado, $bonoMasivo['criterio']);
        $this->assertSame(27, $bonoMasivo['dias']);
        $this->assertSame('exacto', $bonoMasivo['operador']);
        $this->assertEquals(150.0, $bonoMasivo['monto']);
        $this->assertSame($usuario->id, $bonoMasivo['registrado_por']);

        // BONO_NO_REMUNERATIVO tiene es_remunerativo_laboral=false: no debe
        // recalcular AFP/ONP/EsSalud — esto sí ocurre como se esperaba.
        $this->assertSame(['ONP'], collect($snapshot['egresos'])->pluck('codigo')->all());
        $this->assertEquals(390.0, collect($snapshot['egresos'])->firstWhere('codigo', 'ONP')['monto']);
        $this->assertSame(['ESSALUD'], collect($snapshot['aportaciones'])->pluck('codigo')->all());
        $this->assertEquals(270.0, collect($snapshot['aportaciones'])->firstWhere('codigo', 'ESSALUD')['monto']);

        // DISCREPANCIA vs. el reporte del agente de backend / manual test #7:
        // agregarConcepto() SOLO entra al bloque que recalcula AFP/EsSalud/
        // renta 5ta/provisiones cuando `$concepto->es_remunerativo_laboral`
        // es true (ver PlanillaComplementariaService::agregarConcepto(),
        // condición que envuelve también el `if ($concepto->afecta_renta_5ta)`).
        // BONO_NO_REMUNERATIVO tiene es_remunerativo_laboral=false, así que
        // ese bloque completo se salta y RENTA_5TA NO se recalcula pese a que
        // afecta_renta_5ta=true en el seeder (documentado ahí explícitamente
        // como "SÍ afecto a renta de 5ta"). No hay línea RENTA_5TA en el
        // snapshot original ni en el resultante: se verifica el estado real,
        // no el que describe el reporte.
        $this->assertFalse(collect($snapshot['egresos'])->keyBy('codigo')->has('RENTA_5TA'));

        $this->assertEquals(150.0, (float) $detalle->diferencia_ingresos);
        $this->assertEquals(0.0, (float) $detalle->diferencia_egresos);
        $this->assertEquals(0.0, (float) $detalle->diferencia_aportaciones);
        $this->assertEquals(150.0, (float) $detalle->diferencia_neta);
    }

    public function test_no_permite_aplicar_el_mismo_bono_dos_veces_al_mismo_colaborador(): void
    {
        [$empresa, $ciclo, $usuario, $service, $concepto, $definicion] = $this->escenario();

        $boleta = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta->colaborador_id, 27);

        $service->aplicarBonoPorAsistencia(
            $empresa, $ciclo, [$boleta->id], 27, 'exacto', $concepto->id, $definicion->id, 150.0,
            'Bono por asistencia perfecta - Agosto 2026', $usuario->id,
        );

        // La complementaria creada por la primera aplicación queda en
        // estado 'calculada' (aplicarBonoPorAsistencia() SIEMPRE crea una
        // complementaria nueva y nunca la aprueba) — colaboradoresPorAsistencia()
        // vuelve a evaluar "ocupados" primero que "ya recibió este bono", así
        // que el segundo intento falla por tener ya una complementaria
        // pendiente en este ciclo, no específicamente por el criterio del
        // bono. De cualquier forma, la regla de negocio se cumple: el mismo
        // colaborador no puede recibir el bono dos veces sin antes aprobar o
        // eliminar la primera complementaria.
        $this->expectException(ValidationException::class);
        $service->aplicarBonoPorAsistencia(
            $empresa, $ciclo, [$boleta->id], 27, 'exacto', $concepto->id, $definicion->id, 150.0,
            'Segundo intento del mismo bono', $usuario->id,
        );
    }

    public function test_colaborador_con_complementaria_pendiente_por_otro_motivo_no_esta_disponible(): void
    {
        [$empresa, $ciclo, $usuario, $service, $concepto, $definicion] = $this->escenario();

        $boleta = $this->crearColaboradorConBoletaPagada($empresa, $ciclo);
        $this->marcarDiasPresente($empresa, $boleta->colaborador_id, 27);

        // Complementaria pendiente por un motivo totalmente distinto (p.ej.
        // un reintegro de descuentos ya iniciado para este ciclo) — el bono
        // por asistencia no debe poder aplicarse mientras exista.
        $otraComplementaria = PlanillaComplementaria::create([
            'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id,
            'nombre' => 'Reintegro de descuentos', 'motivo' => 'Corrección de un descuento indebido',
            'estado' => 'calculada', 'creado_por' => $usuario->id,
        ]);
        $service->agregarColaboradores($empresa, $otraComplementaria, [$boleta->id]);

        $resultado = collect($service->colaboradoresPorAsistencia($empresa, $ciclo, 27, 'exacto', $concepto->id)['colaboradores']);
        $fila = $resultado->firstWhere('boleta_id', $boleta->id);

        $this->assertNotNull($fila);
        $this->assertSame(27, $fila['dias_asistidos']);
        $this->assertFalse($fila['disponible']);
        $this->assertSame('Ya tiene una complementaria pendiente.', $fila['motivo']);

        $this->expectException(ValidationException::class);
        $service->aplicarBonoPorAsistencia(
            $empresa, $ciclo, [$boleta->id], 27, 'exacto', $concepto->id, $definicion->id, 150.0,
            'Bono por asistencia perfecta - Agosto 2026', $usuario->id,
        );
    }
}
