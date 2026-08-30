<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\ComisionAfp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use App\Modules\Configuracion\Services\ParametroLaboralService;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Database\Seeder;

/**
 * Escenario reproducible para verificar el tope RMA de la prima AFP.
 *
 * Los importes son datos sintéticos de prueba, no valores legales:
 * sueldo S/ 15,000 y RMA S/ 10,000. La prima debe usar S/ 10,000 como
 * base; el aporte obligatorio y la comisión deben conservar S/ 15,000.
 */
class RmaAfpFlowSeeder extends Seeder
{
    private const REGIMENES = ['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios'];

    private const VIGENCIA = '2026-08-01';

    private const SUELDO_PRUEBA = 15000;

    private const RMA_PRUEBA = 10000;

    public function run(ParametroLaboralService $parametros): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            ParametroLaboralDefinicionSeeder::class,
            AfpSeeder::class,
            EmpresaSeeder::class,
            ColaboradorSeeder::class,
        ]);

        $usuario = User::where('username', 'test.user')->firstOrFail();
        $empresa = Empresa::findOrFail($usuario->empresa_id);
        $afp = Afp::where('clave', 'prima')->firstOrFail();
        $definicionRma = ParametroLaboralDefinicion::where('clave', 'rma_afp')->firstOrFail();

        // El administrador de pruebas puede operar empresas sin una fila
        // individual en empresa_user. Preparar todas las empresas activas
        // evita omisiones al calcular ciclos importados (p. ej. BOX PRIME).
        foreach (Empresa::where('activa', true)->get() as $empresaAutorizada) {
            $parametros->inicializarValoresPorDefecto($empresaAutorizada, $usuario);

            foreach (self::REGIMENES as $regimen) {
                ParametroLaboralValor::updateOrCreate(
                    [
                        'empresa_id' => $empresaAutorizada->id,
                        'definicion_id' => $definicionRma->id,
                        'regimen_laboral' => $regimen,
                        'vigencia_desde' => self::VIGENCIA,
                    ],
                    [
                        'valor' => self::RMA_PRUEBA,
                        'creado_por_id' => $usuario->id,
                        'motivo' => 'Dato sintético para probar el tope RMA AFP',
                    ],
                );
            }
        }

        ComisionAfp::updateOrCreate(
            ['afp_id' => $afp->id, 'vigencia_desde' => self::VIGENCIA],
            [
                'aporte_obligatorio_porcentaje' => 10,
                'prima_seguro_porcentaje' => 1.37,
                'comision_flujo_porcentaje' => 1.50,
                'comision_mixta_porcentaje' => 0,
                'sobre_saldo_anual_porcentaje' => 0,
                'creado_por_id' => $usuario->id,
                'motivo' => 'Dato sintético para probar el flujo RMA AFP',
            ],
        );

        $colaborador = Colaborador::where('empresa_id', $empresa->id)
            ->where('afp_id', $afp->id)
            ->where('tipo_comision', 'flujo')
            ->firstOrFail();

        ColaboradorRemuneracion::updateOrCreate(
            ['colaborador_id' => $colaborador->id, 'vigencia_desde' => self::VIGENCIA],
            [
                'salario' => self::SUELDO_PRUEBA,
                'moneda_salario' => 'PEN',
                'periodicidad_pago' => 'mensual',
                'asignacion_familiar' => 0,
            ],
        );

        $ciclo = CicloRemunerativo::updateOrCreate(
            ['empresa_id' => $empresa->id, 'nombre' => 'Prueba RMA AFP - Agosto 2026'],
            [
                'periodicidad' => 'mensual',
                'fecha_inicio' => '2026-08-01',
                'fecha_fin' => '2026-08-31',
                'fecha_corte_asistencia' => '2026-08-31',
                'fecha_pago' => '2026-08-31',
                'estado' => 'borrador',
                'creado_por' => $usuario->id,
            ],
        );

        $this->command?->info('Flujo RMA AFP listo.');
        $this->command?->table(
            ['Dato', 'Valor'],
            [
                ['Usuario', 'test.user / password'],
                ['Empresa', "{$empresa->nombre_comercial} (#{$empresa->id})"],
                ['Colaborador AFP', "{$colaborador->nombres} {$colaborador->apellidos} (#{$colaborador->id})"],
                ['Sueldo de prueba', 'S/ '.number_format(self::SUELDO_PRUEBA, 2)],
                ['RMA de prueba', 'S/ '.number_format(self::RMA_PRUEBA, 2)],
                ['Ciclo', "{$ciclo->nombre} (#{$ciclo->id})"],
                ['Prima esperada', 'S/ 137.00 (1.37% de S/ 10,000.00)'],
            ],
        );
    }
}
