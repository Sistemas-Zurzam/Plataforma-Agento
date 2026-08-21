<?php

namespace App\Modules\Configuracion\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\ParametroLaboralDefinicion;
use App\Modules\Configuracion\Models\ParametroLaboralValor;
use Illuminate\Support\Facades\DB;

class ParametroLaboralService
{
    /**
     * Mismos 4 regímenes que Empresa::regimen_laboral (ver
     * StoreEmpresaRequest/UpdateEmpresaRequest).
     */
    private const REGIMENES = ['General', 'Micro Empresa', 'Pequeña Empresa', 'Locacion de Servicios'];

    /**
     * Valores de referencia para "Inicializar valores por defecto", basados
     * en cifras 2026 (RMV S/1,130, UIT S/5,500) y reglas laborales generales
     * de Perú. No son fuente legal autoritativa: son un punto de partida
     * editable que el admin debe verificar y ajustar según la normativa
     * vigente y la realidad de cada empresa. Para "Locacion de Servicios"
     * (contrato civil, no laboral) los campos de planilla quedan en 0.
     */
    private const VALORES_POR_DEFECTO = [
        'General' => [
            'rmv' => 1130, 'uit' => 5500, 'vacaciones_dias' => 30,
            'essalud_porcentaje' => 9, 'sis_porcentaje' => 0, 'sis_monto_fijo' => 0, 'onp_porcentaje' => 13,
            'afp_aporte_porcentaje' => 10, 'afp_prima_seguro_porcentaje' => 1.37, 'asignacion_familiar_porcentaje' => 10,
            'gratificacion_porcentaje' => 100, 'cts_porcentaje' => 100, 'bonificacion_extraordinaria_porcentaje' => 9,
            'horas_extra_limite_25_h' => 2, 'horas_extra_limite_35_h' => 6,
            'horas_extra_tasa_x25' => 1.25, 'horas_extra_tasa_x35' => 1.35, 'horas_extra_tasa_nocturna' => 2,
            'renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500, 'deduccion_5ta_uit' => 7,
        ],
        'Micro Empresa' => [
            'rmv' => 1130, 'uit' => 5500, 'vacaciones_dias' => 15,
            // essalud_porcentaje ya NO se pone en 0 acá: la tasa legal
            // siempre es 9%, sea cual sea el régimen — lo que decide si se
            // aplica EsSalud o SIS es Empresa.seguro_salud (Micro + inscrita
            // en REMYPE), no el régimen laboral por sí solo.
            'essalud_porcentaje' => 9, 'sis_porcentaje' => 0,
            // Valor de referencia, NO verificado — confirmar el monto SIS
            // real vigente con tu contador antes de usarlo en producción.
            'sis_monto_fijo' => 20, 'onp_porcentaje' => 13,
            'afp_aporte_porcentaje' => 10, 'afp_prima_seguro_porcentaje' => 1.37, 'asignacion_familiar_porcentaje' => 0,
            'gratificacion_porcentaje' => 0, 'cts_porcentaje' => 0, 'bonificacion_extraordinaria_porcentaje' => 0,
            'horas_extra_limite_25_h' => 2, 'horas_extra_limite_35_h' => 6,
            'horas_extra_tasa_x25' => 1.25, 'horas_extra_tasa_x35' => 1.35, 'horas_extra_tasa_nocturna' => 2,
            'renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500, 'deduccion_5ta_uit' => 7,
        ],
        'Pequeña Empresa' => [
            'rmv' => 1130, 'uit' => 5500, 'vacaciones_dias' => 15,
            'essalud_porcentaje' => 9, 'sis_porcentaje' => 0, 'sis_monto_fijo' => 0, 'onp_porcentaje' => 13,
            'afp_aporte_porcentaje' => 10, 'afp_prima_seguro_porcentaje' => 1.37, 'asignacion_familiar_porcentaje' => 10,
            'gratificacion_porcentaje' => 50, 'cts_porcentaje' => 50, 'bonificacion_extraordinaria_porcentaje' => 9,
            'horas_extra_limite_25_h' => 2, 'horas_extra_limite_35_h' => 6,
            'horas_extra_tasa_x25' => 1.25, 'horas_extra_tasa_x35' => 1.35, 'horas_extra_tasa_nocturna' => 2,
            'renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500, 'deduccion_5ta_uit' => 7,
        ],
        'Locacion de Servicios' => [
            'rmv' => 1130, 'uit' => 5500, 'vacaciones_dias' => 0,
            'essalud_porcentaje' => 0, 'sis_porcentaje' => 0, 'sis_monto_fijo' => 0, 'onp_porcentaje' => 0,
            'afp_aporte_porcentaje' => 0, 'afp_prima_seguro_porcentaje' => 0, 'asignacion_familiar_porcentaje' => 0,
            'gratificacion_porcentaje' => 0, 'cts_porcentaje' => 0, 'bonificacion_extraordinaria_porcentaje' => 0,
            'horas_extra_limite_25_h' => 0, 'horas_extra_limite_35_h' => 0,
            'horas_extra_tasa_x25' => 0, 'horas_extra_tasa_x35' => 0, 'horas_extra_tasa_nocturna' => 0,
            'renta_4ta_tasa' => 8, 'renta_4ta_umbral' => 3500, 'deduccion_5ta_uit' => 0,
        ],
    ];

    /**
     * Arma la vista de tarjetas por régimen: para cada régimen y cada
     * definición, el valor vigente es la fila con mayor vigencia_desde. El
     * régimen se marca "completo" cuando las 20 definiciones tienen valor.
     *
     * @return array{regimenes: array<int, array{regimen: string, completo: bool, parametros: array<int, array>}>}
     */
    public function listar(Empresa $empresa): array
    {
        $definiciones = ParametroLaboralDefinicion::orderBy('orden')->get();

        $valoresPorRegimenYDefinicion = ParametroLaboralValor::where('empresa_id', $empresa->id)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->get()
            ->groupBy(['regimen_laboral', 'definicion_id']);

        $regimenes = collect(self::REGIMENES)->map(function (string $regimen) use ($definiciones, $valoresPorRegimenYDefinicion) {
            $valoresDelRegimen = $valoresPorRegimenYDefinicion->get($regimen, collect());

            $parametros = $definiciones->map(function (ParametroLaboralDefinicion $definicion) use ($valoresDelRegimen) {
                $vigente = $valoresDelRegimen->get($definicion->id)?->first();

                return [
                    'definicion_id' => $definicion->id,
                    'clave' => $definicion->clave,
                    'grupo' => $definicion->grupo,
                    'nombre' => $definicion->nombre,
                    'unidad' => $definicion->unidad,
                    'valor' => $vigente?->valor,
                    'vigencia_desde' => $vigente?->vigencia_desde?->toDateString(),
                ];
            })->values();

            return [
                'regimen' => $regimen,
                'completo' => $parametros->every(fn (array $p) => $p['valor'] !== null),
                'parametros' => $parametros,
            ];
        })->values();

        return ['regimenes' => $regimenes];
    }

    /**
     * Registra un nuevo valor vigente. Nunca actualiza ni borra una fila
     * existente: el historial se preserva insertando siempre una fila
     * nueva (regla de auditoría de CLAUDE.md).
     */
    public function crearValor(Empresa $empresa, array $datos): ParametroLaboralValor
    {
        return ParametroLaboralValor::create([
            'empresa_id' => $empresa->id,
            'definicion_id' => $datos['definicion_id'],
            'regimen_laboral' => $datos['regimen_laboral'],
            'vigencia_desde' => $datos['vigencia_desde'],
            'valor' => $datos['valor'],
        ]);
    }

    /**
     * Guarda en lote los valores editados de un régimen (modal "Editar").
     * Solo inserta una fila nueva para las definiciones cuyo valor
     * efectivamente cambió respecto al vigente actual: reenviar el
     * formulario sin tocar un campo no genera una fila duplicada idéntica.
     *
     * @param  array<int, float>  $valoresPorDefinicion  definicion_id => valor
     */
    public function crearValores(Empresa $empresa, string $regimen, string $vigenciaDesde, array $valoresPorDefinicion): void
    {
        DB::transaction(function () use ($empresa, $regimen, $vigenciaDesde, $valoresPorDefinicion) {
            $vigentesActuales = ParametroLaboralValor::where('empresa_id', $empresa->id)
                ->where('regimen_laboral', $regimen)
                ->whereIn('definicion_id', array_keys($valoresPorDefinicion))
                ->orderByDesc('vigencia_desde')
                ->orderByDesc('id')
                ->get()
                ->groupBy('definicion_id')
                ->map(fn ($grupo) => (float) $grupo->first()->valor);

            foreach ($valoresPorDefinicion as $definicionId => $valor) {
                $valorActual = $vigentesActuales->get($definicionId);

                if ($valorActual !== null && (float) $valorActual === (float) $valor) {
                    continue;
                }

                ParametroLaboralValor::create([
                    'empresa_id' => $empresa->id,
                    'definicion_id' => $definicionId,
                    'regimen_laboral' => $regimen,
                    'vigencia_desde' => $vigenciaDesde,
                    'valor' => $valor,
                ]);
            }
        });
    }

    /**
     * Completa únicamente las combinaciones (régimen, definición) que la
     * empresa todavía no tiene ningún valor registrado. Idempotente: no
     * toca combinaciones que ya tengan al menos un valor.
     */
    public function inicializarValoresPorDefecto(Empresa $empresa): void
    {
        DB::transaction(function () use ($empresa) {
            $definicionesPorClave = ParametroLaboralDefinicion::all()->keyBy('clave');

            $existentes = ParametroLaboralValor::where('empresa_id', $empresa->id)
                ->get(['definicion_id', 'regimen_laboral'])
                ->map(fn (ParametroLaboralValor $valor) => "{$valor->definicion_id}|{$valor->regimen_laboral}")
                ->flip();

            $hoy = now()->toDateString();

            foreach (self::VALORES_POR_DEFECTO as $regimen => $valoresPorClave) {
                foreach ($valoresPorClave as $clave => $valorPorDefecto) {
                    $definicion = $definicionesPorClave->get($clave);

                    if (! $definicion || $existentes->has("{$definicion->id}|{$regimen}")) {
                        continue;
                    }

                    ParametroLaboralValor::create([
                        'empresa_id' => $empresa->id,
                        'definicion_id' => $definicion->id,
                        'regimen_laboral' => $regimen,
                        'vigencia_desde' => $hoy,
                        'valor' => $valorPorDefecto,
                    ]);
                }
            }
        });
    }
}
