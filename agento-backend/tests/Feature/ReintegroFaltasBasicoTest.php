<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Services\PlanillaComplementariaService;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreaColaboradorDePrueba;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReintegroFaltasBasicoTest extends TestCase
{
    use RefreshDatabase, CreaColaboradorDePrueba;

    private function escenario(): array
    {
        $this->seed(DatabaseSeeder::class);
        ParametrosVigentesResolver::limpiarCache();
        $empresa = Empresa::firstOrFail();
        app(ParametroLaboralService::class)->inicializarValoresPorDefecto($empresa);
        $usuario = User::where('username', 'test.user')->firstOrFail();
        $afp = Afp::firstOrFail();
        ComisionAfp::updateOrCreate(['afp_id' => $afp->id, 'vigencia_desde' => '2026-08-01'], [
            'aporte_obligatorio_porcentaje' => 10, 'prima_seguro_porcentaje' => 1.37,
            'comision_flujo_porcentaje' => 0, 'comision_mixta_porcentaje' => 0, 'sobre_saldo_anual_porcentaje' => 0,
            'creado_por_id' => $usuario->id, 'motivo' => 'Fixture',
        ]);
        ParametroLaboralValor::create(['empresa_id' => $empresa->id,
            'definicion_id' => ParametroLaboralDefinicion::where('clave', 'rma_afp')->firstOrFail()->id,
            'regimen_laboral' => 'Pequeña Empresa', 'vigencia_desde' => '2026-08-01', 'valor' => 12672.65,
            'creado_por_id' => $usuario->id, 'motivo' => 'Fixture']);
        $banco = Banco::firstOrCreate(['codigo' => 'bcp'], ['nombre' => 'BCP', 'activo' => true]);
        $c = $this->crearColaborador($empresa, ['regimen_laboral' => 'Pequeña Empresa', 'sistema_previsional' => 'afp',
            'afp_id' => $afp->id, 'tipo_comision' => 'mixta', 'fecha_ingreso' => '2026-01-01', 'numero_documento' => '87654321',
            'banco_id' => $banco->id, 'numero_cuenta' => '19123456789012', 'tipo_cuenta' => 'ahorro', 'moneda_cuenta' => 'PEN']);
        $c->remuneraciones()->update(['salario' => 1400]);
        $ciclo = CicloRemunerativo::create(['empresa_id' => $empresa->id, 'nombre' => 'Agosto',
            'fecha_inicio' => '2026-08-01', 'fecha_fin' => '2026-08-31', 'fecha_corte_asistencia' => '2026-08-31', 'fecha_pago' => '2026-08-31', 'estado' => 'pagado']);
        $boleta = Boleta::create(['empresa_id' => $empresa->id, 'colaborador_id' => $c->id, 'ciclo_id' => $ciclo->id,
            'regimen_laboral_snapshot' => 'Pequeña Empresa', 'sueldo_basico_snapshot' => 1400, 'dias_pagados' => 27, 'dias_falta' => 3,
            'total_ingresos' => 1260, 'total_egresos' => 458.48, 'total_aportaciones' => 332.17, 'neto_a_pagar' => 801.52,
            'estado' => 'pagada', 'es_version_vigente' => true, 'snapshot_parametros_version' => 'test', 'snapshot_reglas_version' => 'test', 'calculado_at' => now()]);
        foreach ([
            'SUELDO_BASICO' => ['ingreso', 1260, 1400], 'ADELANTO_SUELDO' => ['egreso', 300, null],
            'DESCUENTO_ERROR_OPERATIVO' => ['egreso', 8.50, null], 'DESCUENTO_TARDANZA' => ['egreso', 7.58, null],
            'AFP_APORTE_OBLIGATORIO' => ['egreso', 125.24, 1252.42], 'AFP_PRIMA_SEGURO' => ['egreso', 17.16, 1252.42],
            'ESSALUD' => ['aportacion', 112.72, 1252.42], 'CTS_PROVISION' => ['aportacion', 52.50, 1260],
            'GRATIFICACION_LEGAL' => ['aportacion', 105, 1260], 'BONIFICACION_EXTRAORDINARIA' => ['aportacion', 9.45, 1260],
            'VACACIONES_PROVISION' => ['aportacion', 52.50, 1260],
        ] as $codigo => [$tipo, $monto, $base]) {
            $boleta->conceptos()->create(['concepto_id' => ConceptoRemuneracion::where('codigo', $codigo)->firstOrFail()->id,
                'tipo' => $tipo, 'monto' => $monto, 'base_utilizada' => $base, 'cantidad' => $codigo === 'SUELDO_BASICO' ? 27 : null,
                'es_remunerativo_laboral' => $tipo === 'ingreso', 'afecta_renta_5ta' => $tipo === 'ingreso']);
        }
        return [$empresa, $ciclo, $boleta, $usuario, app(PlanillaComplementariaService::class)];
    }

    public function test_eros_falta_embebida_restituye_basico_y_calcula_solo_neto_adicional(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $descuentos = collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]));
        $falta = $descuentos->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO');
        $this->assertEquals(140, $falta['monto']);
        $this->assertTrue($falta['aplicado_en_basico']);
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$falta], 'Faltas subsanadas', $usuario->id);
        $d = $item->detalles->first();
        $this->assertSame('140.00', $d->diferencia_ingresos);
        $this->assertSame('15.92', $d->diferencia_egresos);
        $this->assertSame('124.08', $d->diferencia_neta);
        $this->assertSame('925.60', $d->neto_recalculado);
        $this->assertEquals(1400, collect($d->calculo_snapshot['ingresos'])->firstWhere('codigo', 'SUELDO_BASICO')['monto']);
        $this->assertSame('801.52', $boleta->fresh()->neto_a_pagar);
        $this->assertSame('3.00', $boleta->fresh()->dias_falta);
        $service->aprobar($empresa, $item, $usuario->id);
        $cuenta = new EmpresaCuentaBancaria(['tipo_cuenta' => 'corriente', 'moneda' => 'PEN', 'numero_cuenta' => '1912345678901']);
        $lineas = explode("\r\n", trim($service->exportarBcp($empresa, $item, $cuenta, '2026-09-07', 'X')));
        $this->assertSame('00000000000124.08', substr($lineas[1], 177, 17));
        $service->marcarPagada($empresa, $item, $usuario->id, 'TEST');
        $this->assertNull(collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO'));
        $this->expectException(ValidationException::class);
        $service->reintegrarDescuentos($empresa, $ciclo, [$falta], 'Duplicado', $usuario->id);
    }

    public function test_reintegro_parcial_libera_saldo_y_eliminar_restaura_disponibilidad(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $leer = fn () => collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO');
        $falta = $leer();
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [[...$falta, 'monto' => 46.67]], 'Una falta', $usuario->id);
        $this->assertNull($leer());
        $service->eliminar($empresa, $item);
        $this->assertEquals(140, $leer()['monto']);
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [[...$leer(), 'monto' => 46.67]], 'Una falta', $usuario->id);
        $service->aprobar($empresa, $item, $usuario->id);
        $service->marcarPagada($empresa, $item, $usuario->id, 'TEST');
        $this->assertEquals(93.33, $leer()['monto']);
        $resto = $service->reintegrarDescuentos($empresa, $ciclo, [$leer()], 'Saldo faltas', $usuario->id);
        $this->assertSame('925.60', $resto->detalles->first()->neto_recalculado);
    }

    public function test_api_combina_reintegro_de_basico_y_egreso_sin_contar_dos_veces(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $seleccion = collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))
            ->whereIn('codigo', ['DESCUENTO_FALTA_BASICO', 'ADELANTO_SUELDO'])->map(fn ($d) => [...$d, 'indice' => (string) $d['indice']])->values()->all();
        $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($usuario)])
            ->postJson("/api/ciclos-remunerativos/{$ciclo->id}/complementarias/reintegrar-descuentos", ['descuentos' => $seleccion, 'motivo' => 'Subsanación'])
            ->assertCreated()->assertJsonPath('data.total_a_pagar', '424.08');
    }

    public function test_honorarios_no_crea_una_falta_virtual_y_el_basico_corregido_no_la_repite(): void
    {
        [$empresa, $ciclo, $boleta, , $service] = $this->escenario();
        $boleta->update(['regimen_laboral_snapshot' => 'Locacion de Servicios']);
        $this->assertNull(collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO'));
        $boleta->update(['regimen_laboral_snapshot' => 'Pequeña Empresa', 'dias_falta' => 0]);
        $this->assertNull(collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO'));
    }

    public function test_complementaria_recalcula_prima_y_comision_omitidas_junto_con_nuevos_ingresos(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();

        // Simula una boleta histórica ya pagada con el defecto observado:
        // solo se retuvo el aporte obligatorio; prima y comisión quedaron en
        // cero aunque el colaborador sí pertenece a una AFP.
        $boleta->conceptos()
            ->whereHas('concepto', fn ($q) => $q->where('codigo', 'AFP_PRIMA_SEGURO'))
            ->update(['monto' => 0, 'tasa_aplicada' => 0]);
        $boleta->update(['total_egresos' => 441.32, 'neto_a_pagar' => 818.68]);

        $comision = ComisionAfp::where('afp_id', $boleta->colaborador->afp_id)->firstOrFail();
        $comision->update(['comision_flujo_porcentaje' => 1.50]);
        $boleta->colaborador->update(['tipo_comision' => 'flujo']);
        ParametrosVigentesResolver::limpiarCache();

        $item = PlanillaComplementaria::create([
            'ciclo_id' => $ciclo->id,
            'empresa_id' => $empresa->id,
            'nombre' => 'Regularización integral',
            'motivo' => 'Regularización integral',
            'estado' => 'calculada',
            'creado_por' => $usuario->id,
        ]);
        $item = $service->agregarColaboradores($empresa, $item, [$boleta->id]);
        $detalle = $item->detalles->first();
        $bonificacion = ConceptoRemuneracion::where('codigo', 'BONIFICACION')->firstOrFail();

        $item = $service->agregarConcepto(
            $empresa,
            $detalle,
            $bonificacion->id,
            null,
            100,
            'Bono pendiente',
            $usuario->id
        );

        $detalle = $item->detalles->first();
        $egresos = collect($detalle->calculo_snapshot['egresos'])->keyBy('codigo');

        $this->assertEquals(135.24, $egresos['AFP_APORTE_OBLIGATORIO']['monto']);
        $this->assertEquals(18.53, $egresos['AFP_PRIMA_SEGURO']['monto']);
        $this->assertEquals(20.29, $egresos['AFP_COMISION']['monto']);
        $this->assertEquals(51.18, (float) $detalle->diferencia_neta);
        // Al retirar el bono, AFP se vuelve a calcular sobre la base original:
        // la prima omitida sigue regularizada, pero el incremento del bono no.
        $lineaBono = collect($detalle->calculo_snapshot['ingresos'])->firstWhere('codigo', 'BONIFICACION');
        $item = $service->eliminarConcepto($empresa, $detalle, $lineaBono['id']);
        $detalle = $item->detalles->first();
        $egresos = collect($detalle->calculo_snapshot['egresos'])->keyBy('codigo');

        $this->assertEquals(17.16, $egresos['AFP_PRIMA_SEGURO']['monto']);
        $this->assertEquals(18.79, $egresos['AFP_COMISION']['monto']);
    }

    public function test_agrega_y_elimina_bono_en_afiliacion_afp_heredada_incompleta(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $colaborador = $boleta->colaborador;
        $claveAfp = Afp::findOrFail($colaborador->afp_id)->clave;

        $colaborador->update([
            'sistema_previsional' => $claveAfp,
            'afp_id' => null,
            'tipo_comision' => null,
        ]);
        $colaborador->condicionesLaborales()->update([
            'sistema_previsional' => $claveAfp,
            'afp_id' => null,
            'tipo_comision' => null,
        ]);

        $item = PlanillaComplementaria::create([
            'ciclo_id' => $ciclo->id,
            'empresa_id' => $empresa->id,
            'nombre' => 'Regularización heredada',
            'motivo' => 'Regularización heredada',
            'estado' => 'calculada',
            'creado_por' => $usuario->id,
        ]);
        $item = $service->agregarColaboradores($empresa, $item, [$boleta->id]);
        $detalle = $item->detalles->first();
        $bonificacion = ConceptoRemuneracion::where('codigo', 'BONIFICACION')->firstOrFail();

        $item = $service->agregarConcepto(
            $empresa, $detalle, $bonificacion->id, null, 150, 'Bono pendiente', $usuario->id
        );
        $detalle = $item->detalles->first();
        $linea = collect($detalle->calculo_snapshot['ingresos'])->firstWhere('codigo', 'BONIFICACION');

        $this->assertNotNull($linea);
        $this->assertArrayHasKey('AFP_PRIMA_SEGURO', collect($detalle->calculo_snapshot['egresos'])->keyBy('codigo')->all());

        $item = $service->eliminarConcepto($empresa, $detalle, $linea['id']);
        $this->assertNull(collect($item->detalles->first()->calculo_snapshot['ingresos'])->firstWhere('id', $linea['id']));
    }

    public function test_nuevo_borrador_no_hereda_bloqueo_de_feriado_pagado_anterior(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $base = $service->baseParaReintegro($boleta);
        $base['feriado_regularizado'] = ['fecha' => '2026-08-06', 'importe_bruto' => 100];

        $anterior = PlanillaComplementaria::create([
            'ciclo_id' => $ciclo->id, 'empresa_id' => $empresa->id,
            'nombre' => 'Feriado anterior', 'motivo' => 'Pagado',
            'estado' => 'pagada', 'creado_por' => $usuario->id,
        ]);
        PlanillaComplementariaDetalle::create([
            'planilla_complementaria_id' => $anterior->id,
            'boleta_original_id' => $boleta->id,
            'colaborador_id' => $boleta->colaborador_id,
            'neto_original' => $base['neto_a_pagar'],
            'neto_recalculado' => $base['neto_a_pagar'],
            'diferencia_ingresos' => 0, 'diferencia_egresos' => 0,
            'diferencia_aportaciones' => 0, 'diferencia_neta' => 0,
            'calculo_snapshot' => $base,
        ]);

        $nuevo = PlanillaComplementaria::create([
            'ciclo_id' => $ciclo->id, 'empresa_id' => $empresa->id,
            'nombre' => 'Nuevo borrador', 'motivo' => 'Agregar bono',
            'estado' => 'calculada', 'creado_por' => $usuario->id,
        ]);
        $nuevo = $service->agregarColaboradores($empresa, $nuevo, [$boleta->id]);
        $detalle = $nuevo->detalles->first();

        $this->assertArrayNotHasKey('feriado_regularizado', $detalle->calculo_snapshot);

        $bonificacion = ConceptoRemuneracion::where('codigo', 'BONIFICACION')->firstOrFail();
        $actualizado = $service->agregarConcepto(
            $empresa, $detalle, $bonificacion->id, null, 50, 'Bono adicional', $usuario->id
        );

        $this->assertNotNull(collect($actualizado->detalles->first()->calculo_snapshot['ingresos'])
            ->firstWhere('codigo', 'BONIFICACION'));
    }

    public function test_complementaria_aprobada_puede_reabrirse_pero_una_pagada_no(): void
    {
        [$empresa, $ciclo, $boleta, $usuario, $service] = $this->escenario();
        $falta = collect($service->descuentosReintegrables($empresa, $ciclo, [$boleta->id]))
            ->firstWhere('codigo', 'DESCUENTO_FALTA_BASICO');
        $item = $service->reintegrarDescuentos($empresa, $ciclo, [$falta], 'Subsanación', $usuario->id);
        $item = $service->aprobar($empresa, $item, $usuario->id);

        $reabierta = $service->reabrir($empresa, $item, $usuario->id, 'Faltó agregar un bono');

        $this->assertSame('calculada', $reabierta->estado);
        $this->assertNull($reabierta->aprobado_por);
        $this->assertNull($reabierta->aprobado_at);
        $this->assertSame('Faltó agregar un bono', $reabierta->detalles->first()->calculo_snapshot['reaperturas'][0]['motivo']);

        $reaprobada = $service->aprobar($empresa, $reabierta, $usuario->id);
        $pagada = $service->marcarPagada($empresa, $reaprobada, $usuario->id, 'PAGO-TEST');

        $this->expectException(ValidationException::class);
        $service->reabrir($empresa, $pagada, $usuario->id, 'No debe permitirse');
    }
}
