<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Services\DescansoSemanalComplementariaService;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DescansoSemanalComplementariaTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function escenario(int $cantidad = 1): array
    {
        $this->seed(DatabaseSeeder::class);
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $ciclo = CicloRemunerativo::create(['empresa_id' => $empresa->id, 'nombre' => 'Agosto',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31',
            'fecha_pago' => '2026-08-31', 'estado' => 'pagado']);
        $banco = Banco::firstOrCreate(['codigo' => 'bcp'], ['nombre' => 'BCP', 'activo' => true]);
        $boletas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $c = $this->crearColaborador($empresa, ['fecha_ingreso' => '2026-01-01', 'numero_documento' => (string) (85000000 + $i),
                'banco_id' => $banco->id, 'numero_cuenta' => '19123456789012', 'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN']);
            $c->remuneraciones()->update(['salario' => 1400]);
            ColaboradorHorarioAsignacion::create(['empresa_id' => $empresa->id, 'colaborador_id' => $c->id,
                'horario_id' => $c->horario_id, 'dias_descanso_rotativo_por_semana' => 1, 'vigencia_desde' => '2026-01-01']);
            $b = Boleta::create(['empresa_id' => $empresa->id, 'colaborador_id' => $c->id, 'ciclo_id' => $ciclo->id,
                'regimen_laboral_snapshot' => 'General', 'sueldo_basico_snapshot' => 1400, 'dias_pagados' => 30,
                'total_ingresos' => 1400, 'total_egresos' => 182, 'total_aportaciones' => 126, 'neto_a_pagar' => 1218,
                'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test',
                'snapshot_reglas_version' => 'test', 'calculado_at' => now()]);
            foreach (['SUELDO_BASICO' => ['ingreso', 1400], 'ONP' => ['egreso', 182], 'ESSALUD' => ['aportacion', 126]] as $codigo => [$tipo, $monto]) {
                $b->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                    'tipo' => $tipo, 'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso',
                    'base_utilizada' => 1400, 'monto' => $monto]);
            }
            for ($f = Carbon::parse('2026-08-17'); $f->lte('2026-08-31'); $f->addDay()) {
                AsistenciaResultadoDiario::create(['empresa_id' => $empresa->id, 'colaborador_id' => $c->id,
                    'fecha' => $f->toDateString(), 'tipo_dia' => 'laborable', 'estado' => 'presente',
                    'minutos_trabajados' => 480, 'minutos_programados' => 480, 'procesado_at' => now()]);
            }
            $boletas[] = $b;
        }
        return [$empresa, $ciclo, $boletas, $usuario, app(DescansoSemanalComplementariaService::class)];
    }

    public function test_dos_semanas_generan_solo_reintegro_neto_y_txt(): void
    {
        [$empresa, $ciclo, $boletas, $usuario, $service] = $this->escenario();
        $ids = [$boletas[0]->id];
        $semanas = $service->semanas($empresa, $ciclo, $ids);
        $this->assertSame(['2026-08-17', '2026-08-24'], array_column($semanas, 'semana_inicio'));
        $this->assertSame('93.33', $semanas[0]['importe_bruto']);
        $item = $service->crear($empresa, $ciclo, $semanas, 'Dos descansos no gozados', $usuario->id);
        $d = $item->detalles->first();
        $this->assertSame('186.66', $d->diferencia_ingresos);
        $this->assertSame('162.39', $d->diferencia_neta);
        $this->assertCount(2, $d->calculo_snapshot['descansos_semanales']);
        $this->assertSame('1218.00', $boletas[0]->fresh()->neto_a_pagar);
        $this->assertSame([], $service->semanas($empresa, $ciclo, $ids));
        $comp = app(PlanillaComplementariaService::class);
        $comp->aprobar($empresa, $item, $usuario->id);
        $cuenta = new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']);
        $lineas = explode("\r\n", trim($comp->exportarBcp($empresa, $item, $cuenta, '2026-09-06', 'X')));
        $this->assertCount(2, $lineas);
        $this->assertSame('00000000000162.39', substr($lineas[1], 177, 17));
        $comp->marcarPagada($empresa, $item, $usuario->id, 'TEST');
        $this->expectException(ValidationException::class);
        $service->crear($empresa, $ciclo, $semanas, 'Duplicado', $usuario->id);
    }

    public function test_lote_y_eliminacion_liberan_semanas(): void
    {
        [$empresa, $ciclo, $boletas, $usuario, $service] = $this->escenario(2);
        $ids = array_map(fn ($b) => $b->id, $boletas);
        $semanas = $service->semanas($empresa, $ciclo, $ids);
        $item = $service->crear($empresa, $ciclo, $semanas, 'Lote', $usuario->id);
        $this->assertCount(2, $item->detalles);
        $this->assertSame([], $service->semanas($empresa, $ciclo, $ids));
        app(PlanillaComplementariaService::class)->eliminar($empresa, $item);
        $this->assertCount(4, $service->semanas($empresa, $ciclo, $ids));
    }

    public function test_descanso_real_y_datos_incompletos_no_generan_semanas(): void
    {
        [$empresa, $ciclo, $boletas, , $service] = $this->escenario();
        AsistenciaResultadoDiario::withoutGlobalScopes()->where('colaborador_id', $boletas[0]->colaborador_id)->whereDate('fecha', '2026-08-20')->update(['estado' => 'descanso', 'minutos_trabajados' => 0]);
        AsistenciaResultadoDiario::withoutGlobalScopes()->where('colaborador_id', $boletas[0]->colaborador_id)->whereDate('fecha', '2026-08-27')->delete();
        $this->assertSame([], $service->semanas($empresa, $ciclo, [$boletas[0]->id]));
    }

    public function test_http_exige_confirmaciones_y_revalida_semanas(): void
    {
        [$empresa, $ciclo, $boletas, $usuario, $service] = $this->escenario();
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)]);
        $url = "/api/ciclos-remunerativos/{$ciclo->id}/complementarias/descansos-semanales";
        $s = $this->getJson($url.'?boleta_ids[]='.$boletas[0]->id)->assertOk()->json('data');
        $this->postJson($url, ['semanas' => $s, 'motivo' => 'Test'])->assertUnprocessable();
        $this->postJson($url, ['semanas' => $s, 'motivo' => 'Test', 'sin_descanso_sustitutorio' => true, 'sin_pago_previo' => true])
            ->assertCreated()->assertJsonPath('data.detalles.0.descansos_semanales.0.semana_inicio', '2026-08-17');
    }

    public function test_sustitutorio_y_pago_previo_bloquean_el_reintegro(): void
    {
        [$empresa, $ciclo, $boletas, $usuario, $service] = $this->escenario();
        $ids = [$boletas[0]->id];
        \App\Modules\Personas\Models\ColaboradorCalendarioDia::create([
            'colaborador_id' => $boletas[0]->colaborador_id, 'fecha' => '2026-08-24', 'tipo' => 'descanso', 'origen' => 'descanso_sustitutorio',
        ]);
        $semanas = $service->semanas($empresa, $ciclo, $ids);
        $this->assertFalse($semanas[0]['disponible']);
        $this->assertStringContainsString('sustitutorio', $semanas[0]['observacion']);
        $this->expectException(ValidationException::class);
        $service->crear($empresa, $ciclo, $semanas, 'No debe aceptar', $usuario->id);
    }

    public function test_he100_historica_sin_fecha_no_se_paga_dos_veces(): void
    {
        [$empresa, $ciclo, $boletas, , $service] = $this->escenario();
        $boletas[0]->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', 'HE_100')->firstOrFail()->id,
            'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'monto' => 93.33]);
        $semanas = $service->semanas($empresa, $ciclo, [$boletas[0]->id]);
        $this->assertFalse($semanas[0]['disponible']);
        $this->assertStringContainsString('100%', $semanas[0]['observacion']);
    }

    public function test_api_no_admite_boletas_de_otro_ciclo(): void
    {
        [$empresa, $ciclo, $boletas, $usuario] = $this->escenario();
        $otro = $ciclo->replicate();
        $otro->fecha_inicio = '2026-09-01';
        $otro->fecha_fin = '2026-09-30';
        $otro->save();
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)])
            ->getJson("/api/ciclos-remunerativos/{$otro->id}/complementarias/descansos-semanales?boleta_ids[]=".$boletas[0]->id)
            ->assertUnprocessable();
    }
}
