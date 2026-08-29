<?php

namespace Database\Seeders;

use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Services\ColaboradorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Colaboradores de prueba para las 5 empresas reales del sistema, pensados
 * para ejercitar la mayor variedad posible de estados/régimen/previsional/
 * parámetros sin tocar la lógica de cálculo — solo datos.
 *
 * Requiere SedeAreaHorarioSeeder (se invoca automáticamente si hace falta).
 * Reutiliza ColaboradorService::crear() en vez de Eloquent directo, para
 * que remuneración inicial + asignación de horario + calendario del mes de
 * ingreso se generen exactamente igual que desde el wizard de la UI.
 */
class ColaboradorSeeder extends Seeder
{
    private const CANTIDAD_POR_EMPRESA = [
        'Zazu' => 5,
        'Agento' => 5,
        'Texajo' => 5,
        'Overshark' => 5,
        'Bravos' => 5,
    ];

    /**
     * 25 nombres únicos, consumidos secuencialmente entre las 5 empresas
     * para que ninguno se repita en todo el seeder. [nombres, apellido
     * paterno, apellido materno] — separados a mano (no es un split
     * automático de un string existente, es data de prueba nueva), ver
     * migración de apellido_paterno/apellido_materno.
     */
    private const NOMBRES = [
        ['Carlos', 'Ramírez', 'Soto'], ['María', 'Torres', 'Vega'], ['Jorge', 'Quispe', 'Mamani'],
        ['Lucía', 'Fernández', 'Rojas'], ['Miguel', 'Salazar', 'Castro'], ['Ana', 'Chávez', 'Paredes'],
        ['Diego', 'Huamán', 'Flores'], ['Valeria', 'Cruz', 'Espinoza'], ['Fernando', 'Rodríguez', 'Loayza'],
        ['Gabriela', 'Ponce', 'Delgado'], ['Renzo', 'Vargas', 'Injante'], ['Camila', 'Mendoza', 'Ríos'],
        ['Alonso', 'Guerrero', 'Núñez'], ['Daniela', 'Zapata', 'Cárdenas'], ['Eduardo', 'Pacheco', 'Villar'],
        ['Fiorella', 'Aguirre', 'Bustamante'], ['Iván', 'Chumpitaz', 'León'], ['Karla', 'Vidal', 'Otero'],
        ['Luis', 'Bendezú', 'Campos'], ['Milagros', 'Suárez', 'Aranda'], ['Nestor', 'Palacios', 'Contreras'],
        ['Paola', 'Reyes', 'Manrique'], ['Ricardo', 'Ochoa', 'Del Águila'], ['Sofía', 'Trujillo', 'Peña'],
        ['Yuri', 'Escalante', 'Vergara'],
    ];

    private readonly ColaboradorService $colaboradores;

    private int $indiceGlobal = 0;

    public function __construct()
    {
        $this->colaboradores = new ColaboradorService;
    }

    public function run(): void
    {
        $this->call(SedeAreaHorarioSeeder::class);

        $afps = Afp::pluck('id', 'clave');

        foreach (self::CANTIDAD_POR_EMPRESA as $nombreEmpresa => $cantidad) {
            $empresa = Empresa::where('nombre_comercial', 'like', "%{$nombreEmpresa}%")->first();

            if (! $empresa) {
                $this->command?->warn("ColaboradorSeeder: no se encontró ninguna empresa similar a \"{$nombreEmpresa}\" — se omite.");

                continue;
            }

            $sede = Sede::where('empresa_id', $empresa->id)->where('activa', true)->first();
            $areas = Area::where('empresa_id', $empresa->id)->get()->keyBy('nombre');
            $horarioEstandar = Horario::where('empresa_id', $empresa->id)->where('nombre', 'Horario Estándar')->first();
            $horarioHibrido = Horario::where('empresa_id', $empresa->id)->where('nombre', 'Horario Híbrido')->first();

            if (! $sede || $areas->isEmpty() || ! $horarioEstandar || ! $horarioHibrido) {
                $this->command?->warn("ColaboradorSeeder: faltan sede/área/horario para \"{$empresa->nombre_comercial}\" — se omite.");

                continue;
            }

            $arquetipos = $this->arquetipos($empresa, $sede, $areas, $horarioEstandar, $horarioHibrido, $afps);

            foreach (array_slice($arquetipos, 0, $cantidad) as $arquetipo) {
                $this->crearColaborador($empresa, $arquetipo);
            }

            $this->command?->info("ColaboradorSeeder: {$cantidad} colaborador(es) procesado(s) para \"{$empresa->nombre_comercial}\".");
        }
    }

    private function crearColaborador(Empresa $empresa, array $arquetipo): void
    {
        [$nombres, $apellidoPaterno, $apellidoMaterno] = self::NOMBRES[$this->indiceGlobal % count(self::NOMBRES)];
        $dni = (string) (70000001 + $this->indiceGlobal);
        $indice = $this->indiceGlobal;
        $this->indiceGlobal++;

        $yaExiste = Colaborador::withTrashed()
            ->where('empresa_id', $empresa->id)
            ->where('tipo_documento', 'dni')
            ->where('numero_documento', $dni)
            ->exists();

        if ($yaExiste) {
            return;
        }

        $horario = $arquetipo['horario'];
        $cesarDespues = $arquetipo['_cesar'] ?? null;
        unset($arquetipo['horario'], $arquetipo['_cesar']);

        $datosPersonales = [
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'tipo_documento' => 'dni',
            'numero_documento' => $dni,
            'fecha_nacimiento' => Carbon::now()->subYears(28)->subDays($indice * 11)->toDateString(),
            'pais_residencia' => 'Perú',
            'ciudad_residencia' => 'Lima',
            'distrito_residencia' => 'Lima',
            'direccion' => 'Sin dirección registrada',
            'email' => null,
            'celular_colaborador' => '9'.str_pad((string) (10000000 + $indice), 8, '0', STR_PAD_LEFT),
            'celular_referencia' => '9'.str_pad((string) (20000000 + $indice), 8, '0', STR_PAD_LEFT),
            'banco' => 'BCP',
            'numero_cuenta' => str_pad((string) (100000000 + $indice), 14, '0', STR_PAD_LEFT),
            'tipo_cuenta' => 'ahorro',
            'moneda_cuenta' => 'PEN',
            'cci' => str_pad((string) (200000000000000 + $indice), 20, '0', STR_PAD_LEFT),
            'cts_cuenta' => null,
            'contabilizar_tardanzas' => true,
            'contabilizar_faltas' => true,
            'contabilizar_horas_extra' => true,
        ];

        $datos = array_merge($datosPersonales, $arquetipo, ['horario_id' => $horario->id]);

        try {
            $datos['calendario'] = $this->colaboradores->calendarioPorDefecto($horario, $datos['fecha_ingreso'])['dias'];
        } catch (Throwable $exception) {
            $this->command?->warn("ColaboradorSeeder: no se pudo generar el calendario para {$nombres} {$apellidoPaterno} ({$exception->getMessage()}) — se omite.");

            return;
        }

        try {
            $colaborador = $this->colaboradores->crear($empresa, $datos);

            if ($cesarDespues) {
                $this->colaboradores->cesar($empresa, $colaborador, $cesarDespues['fecha_cese'], $cesarDespues['motivo']);
            }
        } catch (Throwable $exception) {
            $this->command?->warn("ColaboradorSeeder: no se pudo crear a {$nombres} {$apellidoPaterno} en {$empresa->nombre_comercial} ({$exception->getMessage()}).");
        }
    }

    /**
     * 10 arquetipos en orden de prioridad — cada empresa toma los primeros
     * N según su cupo (array_slice), así las empresas con menos
     * colaboradores igual cubren primero los casos más importantes
     * (dependiente estándar, locador, cesado) y las más grandes (Texajo, 8)
     * cubren casi toda la matriz de régimen/previsional/estado.
     *
     * @return array<int, array<string, mixed>>
     */
    private function arquetipos(
        Empresa $empresa,
        Sede $sede,
        Collection $areas,
        Horario $horarioEstandar,
        Horario $horarioHibrido,
        Collection $afps,
    ): array {
        $regimenEmpresa = $empresa->regimen_laboral ?? 'General';
        $areaOperaciones = $areas->get('Operaciones', $areas->first());
        $areaAdmin = $areas->get('Administración', $areas->first());
        $areaComercial = $areas->get('Comercial', $areas->first());

        $base = fn (array $extra) => array_merge([
            'sede_id' => $sede->id,
            'modalidad_trabajo' => 'presencial',
            'moneda_salario' => 'PEN',
            'periodicidad_pago' => 'mensual',
            'asignacion_familiar' => 0,
            'sistema_previsional' => null,
            'afp_id' => null,
            'tipo_comision' => null,
            'cuspp' => null,
            'tiene_hijos_asignacion_familiar' => false,
            'tiene_suspension_renta_4ta' => false,
        ], $extra);

        return [
            // 1. Dependiente estándar activo, ONP, sueldo medio.
            $base([
                'area_id' => $areaOperaciones->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Analista de Operaciones',
                'tipo_contrato' => 'indefinido',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subMonths(8)->startOfMonth()->toDateString(),
                'sistema_previsional' => 'onp',
                'salario' => 2500,
            ]),

            // 2. Locador (recibos por honorarios) — motor de RH separado.
            $base([
                'area_id' => $areaComercial->id,
                'horario' => $horarioHibrido,
                'modalidad_trabajo' => 'remoto',
                'cargo' => 'Consultor Externo',
                'tipo_contrato' => 'locacion_servicios',
                'tipo_trabajador' => 'locador',
                'regimen_laboral' => 'Locacion de Servicios',
                'fecha_ingreso' => Carbon::now()->subMonths(4)->startOfMonth()->toDateString(),
                'salario' => 2000,
            ]),

            // 3. Dependiente cesado — prueba exclusión/prorrateo en ciclos.
            $base([
                'area_id' => $areaAdmin->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Asistente Administrativo',
                'tipo_contrato' => 'indefinido',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subYear()->startOfMonth()->toDateString(),
                'sistema_previsional' => 'onp',
                'salario' => 2200,
                '_cesar' => [
                    'fecha_cese' => Carbon::now()->subMonths(2)->endOfMonth()->toDateString(),
                    'motivo' => 'Renuncia voluntaria',
                ],
            ]),

            // 4. Dependiente a plazo fijo, AFP Prima (flujo), con hijos,
            // sueldo exactamente en el RMV (prueba el piso legal de EsSalud).
            $base([
                'area_id' => $areaOperaciones->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Operario de Producción',
                'tipo_contrato' => 'plazo_fijo',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subMonths(3)->startOfMonth()->toDateString(),
                'fecha_fin_contrato' => Carbon::now()->addMonths(9)->toDateString(),
                'sistema_previsional' => $afps->has('prima') ? 'prima' : 'onp',
                'afp_id' => $afps->get('prima'),
                'tipo_comision' => $afps->has('prima') ? 'flujo' : null,
                'cuspp' => $afps->has('prima') ? 'PRI-'.str_pad((string) (1000 + $this->indiceGlobal), 6, '0', STR_PAD_LEFT) : null,
                'tiene_hijos_asignacion_familiar' => true,
                'salario' => 1130,
            ]),

            // 5. Practicante — modalidad formativa, sin previsional, subvención baja.
            $base([
                'area_id' => $areaAdmin->id,
                'horario' => $horarioHibrido,
                'modalidad_trabajo' => 'hibrido',
                'cargo' => 'Practicante Administrativo',
                'tipo_contrato' => 'practicas',
                'tipo_trabajador' => 'practicante',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subMonths(2)->startOfMonth()->toDateString(),
                'fecha_fin_contrato' => Carbon::now()->addMonths(4)->toDateString(),
                'salario' => 1025,
            ]),

            // 6. Dependiente activo, AFP Profuturo (flujo), sueldo alto
            // (cruza varios tramos de renta de 5ta), remoto.
            $base([
                'area_id' => $areaComercial->id,
                'horario' => $horarioHibrido,
                'modalidad_trabajo' => 'remoto',
                'contabilizar_tardanzas' => false,
                'cargo' => 'Gerente Comercial',
                'tipo_contrato' => 'indefinido',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subMonths(6)->startOfMonth()->toDateString(),
                'sistema_previsional' => $afps->has('profuturo') ? 'profuturo' : 'onp',
                'afp_id' => $afps->get('profuturo'),
                'tipo_comision' => $afps->has('profuturo') ? 'flujo' : null,
                'cuspp' => $afps->has('profuturo') ? 'PRO-'.str_pad((string) (1000 + $this->indiceGlobal), 6, '0', STR_PAD_LEFT) : null,
                'salario' => 8000,
            ]),

            // 7. Locador con suspensión de renta de 4ta vigente.
            $base([
                'area_id' => $areaComercial->id,
                'horario' => $horarioHibrido,
                'modalidad_trabajo' => 'remoto',
                'cargo' => 'Consultor Externo Senior',
                'tipo_contrato' => 'locacion_servicios',
                'tipo_trabajador' => 'locador',
                'regimen_laboral' => 'Locacion de Servicios',
                'fecha_ingreso' => Carbon::now()->subMonths(10)->startOfMonth()->toDateString(),
                'tiene_suspension_renta_4ta' => true,
                'salario' => 900,
            ]),

            // 8. Dependiente con antigüedad larga (3 años), AFP Integra
            // (flujo), con hijos — CTS/gratificación con historial real.
            $base([
                'area_id' => $areaOperaciones->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Jefe de Operaciones',
                'tipo_contrato' => 'indefinido',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subYears(3)->startOfMonth()->toDateString(),
                'sistema_previsional' => $afps->has('integra') ? 'integra' : 'onp',
                'afp_id' => $afps->get('integra'),
                'tipo_comision' => $afps->has('integra') ? 'flujo' : null,
                'cuspp' => $afps->has('integra') ? 'INT-'.str_pad((string) (1000 + $this->indiceGlobal), 6, '0', STR_PAD_LEFT) : null,
                'tiene_hijos_asignacion_familiar' => true,
                'salario' => 3500,
            ]),

            // 9. Dependiente a plazo fijo, ONP, ingreso reciente (mismo mes) —
            // prueba el cálculo proporcional del primer periodo.
            $base([
                'area_id' => $areaAdmin->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Asistente de Recursos Humanos',
                'tipo_contrato' => 'plazo_fijo',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->startOfMonth()->addDays(4)->toDateString(),
                'fecha_fin_contrato' => Carbon::now()->addYear()->toDateString(),
                'sistema_previsional' => 'onp',
                'salario' => 1800,
            ]),

            // 10. Dependiente con antigüedad media (1 año), AFP Habitat
            // (flujo), cuenta bancaria en USD.
            $base([
                'area_id' => $areaComercial->id,
                'horario' => $horarioEstandar,
                'cargo' => 'Ejecutivo Comercial',
                'tipo_contrato' => 'indefinido',
                'tipo_trabajador' => 'trabajador',
                'regimen_laboral' => $regimenEmpresa,
                'fecha_ingreso' => Carbon::now()->subYear()->addMonths(1)->startOfMonth()->toDateString(),
                'sistema_previsional' => $afps->has('habitat') ? 'habitat' : 'onp',
                'afp_id' => $afps->get('habitat'),
                'tipo_comision' => $afps->has('habitat') ? 'flujo' : null,
                'cuspp' => $afps->has('habitat') ? 'HAB-'.str_pad((string) (1000 + $this->indiceGlobal), 6, '0', STR_PAD_LEFT) : null,
                'moneda_cuenta' => 'USD',
                'salario' => 3000,
            ]),
        ];
    }
}
