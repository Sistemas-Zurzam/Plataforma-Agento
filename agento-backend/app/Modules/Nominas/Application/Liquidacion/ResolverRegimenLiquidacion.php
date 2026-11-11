<?php

namespace App\Modules\Nominas\Application\Liquidacion;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Carbon;

/**
 * Régimen laboral aplicable a cada período del vínculo, para la liquidación.
 *
 * Fuente: el historial append-only ColaboradorCondicionLaboral (vigencia_desde)
 * — nunca el valor mutable actual del colaborador, salvo que no exista
 * historial. Contrasta cada tramo MYPE con el sustento REMYPE de la empresa.
 *
 * Cuando el régimen no se puede determinar (vacío, vigencias superpuestas,
 * MYPE sin inscripción, vínculo anterior a la inscripción, categoría de la
 * empresa distinta, tramos de locación dentro del vínculo laboral) devuelve
 * una validación BLOQUEANTE con la pregunta exacta: la liquidación puede
 * guardarse como borrador pero no aprobarse hasta que RR.HH. registre la
 * decisión (con sustento) en el dato `clave` correspondiente.
 *
 * Reglas citadas: TUO Ley MYPE (D.S. 013-2013-PRODUCE) y Ley 32353 art. 42.2
 * (contratos anteriores a la ley se rigen por la norma de su celebración),
 * art. 51 (la MYPE debe acreditarse ante la AAT) y art. 52.2 (incumplimiento:
 * excluidos del régimen especial, derechos íntegros).
 */
class ResolverRegimenLiquidacion
{
    public const LOCACION = 'Locacion de Servicios';
    public const MYPE = ['Micro Empresa', 'Pequeña Empresa'];
    public const LABORALES = ['General', 'Micro Empresa', 'Pequeña Empresa'];

    /**
     * @param  array<string, ?string>  $decisiones  clave de validación => opción elegida
     * @return array{tramos: array<int, array{desde: string, hasta: string, regimen: ?string, origen: string}>, validaciones: array<int, array<string, mixed>>, alertas: array<int, string>, regimen_al_cese: ?string}
     */
    public function resolver(Empresa $empresa, Colaborador $colaborador, Carbon $ingreso, Carbon $cese, array $decisiones = []): array
    {
        $validaciones = [];
        $alertas = [];
        $decision = fn (string $clave) => $decisiones[$clave] ?? null;

        // --- 1. Eventos del historial (una fecha = un régimen) ---
        $filas = ColaboradorCondicionLaboral::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $cese->toDateString())
            ->orderBy('vigencia_desde')->orderBy('id')->get(['regimen_laboral', 'vigencia_desde', 'id']);
        $eventos = [];
        foreach ($filas->groupBy(fn ($f) => $f->vigencia_desde->toDateString()) as $fecha => $grupo) {
            $distintos = $grupo->pluck('regimen_laboral')->unique()->values();
            $regimen = $grupo->last()->regimen_laboral;
            if ($distintos->count() > 1) {
                $elegido = $decision('decision_regimen_superpuesto');
                $validaciones[] = $this->validacion('decision_regimen_superpuesto', 'Régimen laboral por validar: vigencias superpuestas',
                    "El historial registra el {$fecha} más de un régimen (".$distintos->map(fn ($r) => $r ?? 'sin régimen')->implode(', ').'). ¿Cuál rige desde esa fecha?',
                    $distintos->filter()->map(fn ($r) => ['value' => $r, 'label' => $r])->values()->all(), 'Historial de condición laboral', $elegido);
                $regimen = $elegido ?? $regimen;
            }
            $eventos[] = ['fecha' => Carbon::parse($fecha), 'regimen' => $regimen, 'origen' => 'historial'];
        }

        if ($eventos === []) {
            $eventos[] = ['fecha' => $ingreso->copy(), 'regimen' => $colaborador->regimen_laboral, 'origen' => 'ficha'];
            $alertas[] = 'El vínculo no tiene historial de condición laboral: se usa el régimen de la ficha para todo el período.';
        } elseif ($eventos[0]['fecha']->gt($ingreso)) {
            $alertas[] = "El historial de condición laboral empieza el {$eventos[0]['fecha']->format('d/m/Y')}: el período desde el ingreso se asume con el régimen {$eventos[0]['regimen']}.";
            array_unshift($eventos, ['fecha' => $ingreso->copy(), 'regimen' => $eventos[0]['regimen'], 'origen' => 'supuesto']);
        }

        // Fechas anteriores al ingreso: rige la última de ellas desde el ingreso.
        $previos = array_values(array_filter($eventos, fn ($e) => $e['fecha']->lte($ingreso)));
        $posteriores = array_values(array_filter($eventos, fn ($e) => $e['fecha']->gt($ingreso)));
        $eventos = [['fecha' => $ingreso->copy(), 'regimen' => end($previos)['regimen'], 'origen' => end($previos)['origen']], ...$posteriores];

        $tramos = [];
        foreach ($eventos as $i => $evento) {
            $hasta = isset($eventos[$i + 1]) ? $eventos[$i + 1]['fecha']->copy()->subDay() : $cese->copy();
            $tramos[] = ['desde' => $evento['fecha']->copy(), 'hasta' => $hasta, 'regimen' => $evento['regimen'], 'origen' => $evento['origen']];
        }

        // --- 2. Régimen vacío ---
        if (collect($tramos)->contains(fn ($t) => blank($t['regimen']))) {
            $elegido = $decision('decision_regimen_vacio');
            $validaciones[] = $this->validacion('decision_regimen_vacio', 'Régimen laboral por validar: sin régimen registrado',
                'El vínculo no tiene régimen laboral en '.collect($tramos)->filter(fn ($t) => blank($t['regimen']))->map(fn ($t) => $this->periodo($t))->implode(', ').'. ¿Qué régimen corresponde?',
                $this->opcionesRegimen(), 'Ficha y condición laboral del colaborador', $elegido);
            $tramos = array_map(fn ($t) => blank($t['regimen']) ? [...$t, 'regimen' => $elegido, 'origen' => $elegido ? 'decision' : $t['origen']] : $t, $tramos);
        }

        // --- 3. Locación dentro del vínculo laboral ---
        $hayLocacion = collect($tramos)->contains('regimen', self::LOCACION);
        $hayLaboral = collect($tramos)->contains(fn ($t) => in_array($t['regimen'], self::LABORALES, true));
        if ($hayLocacion && $hayLaboral) {
            $elegido = $decision('decision_tramo_locacion');
            $validaciones[] = $this->validacion('decision_tramo_locacion', 'Régimen laboral por validar: período de locación de servicios',
                'Parte del vínculo figura como locación de servicios ('.collect($tramos)->where('regimen', self::LOCACION)->map(fn ($t) => $this->periodo($t))->implode(', ').'). ¿Ese período computa para beneficios laborales?',
                [['value' => 'excluir_tramo', 'label' => 'No: era locación autónoma, no computa'], ['value' => 'computar_como_laboral', 'label' => 'Sí: fue relación laboral (computa con el régimen siguiente)']],
                'Contrato civil frente a relación laboral', $elegido);
            if ($elegido === 'computar_como_laboral') {
                $siguiente = collect($tramos)->first(fn ($t) => in_array($t['regimen'], self::LABORALES, true))['regimen'];
                $tramos = array_map(fn ($t) => $t['regimen'] === self::LOCACION ? [...$t, 'regimen' => $siguiente, 'origen' => 'decision'] : $t, $tramos);
            } else {
                // Sin decisión se calcula excluyéndolo (borrador): no computa tiempo civil.
                $tramos = array_values(array_filter($tramos, fn ($t) => $t['regimen'] !== self::LOCACION));
            }
        }
        if ($colaborador->tipo_contrato === 'locacion_servicios' && $hayLaboral) {
            $elegido = $decision('decision_clasificacion_vinculo');
            $validaciones[] = $this->validacion('decision_clasificacion_vinculo', 'Régimen laboral por validar: clasificación del vínculo',
                'El contrato figura como locación de servicios, pero el régimen es laboral. ¿El vínculo fue laboral (en planilla)?',
                [['value' => 'laboral', 'label' => 'Sí, vínculo laboral en planilla'], ['value' => 'locacion', 'label' => 'No, locación autónoma: no corresponde liquidación laboral']],
                'Tipo de contrato del colaborador', $elegido);
        }

        // --- 4. Sustento REMYPE de los tramos MYPE ---
        if (collect($tramos)->contains(fn ($t) => in_array($t['regimen'], self::MYPE, true))) {
            $inscripcion = $empresa->fecha_inscripcion_remype ? Carbon::parse($empresa->fecha_inscripcion_remype) : null;
            if (! $empresa->inscrita_remype || ! $inscripcion) {
                $elegido = $decision('decision_mype_sin_remype');
                $validaciones[] = $this->validacion('decision_mype_sin_remype', 'Régimen laboral por validar: empresa sin inscripción REMYPE',
                    "{$empresa->nombre_comercial} no tiene registrada su inscripción en el REMYPE, pero el vínculo figura en régimen MYPE. ¿Qué régimen aplica?",
                    [['value' => 'aplicar_general', 'label' => 'Régimen general (sin acreditación MYPE)'], ['value' => 'mantener_mype', 'label' => 'Régimen MYPE (inscripción acreditada fuera del sistema)']],
                    'Ley 32353 arts. 51 y 52.2; TUO Ley MYPE', $elegido);
                if ($elegido === 'aplicar_general') {
                    $tramos = $this->reemplazarMype($tramos, null);
                }
            } elseif ($ingreso->lt($inscripcion)) {
                $elegido = $decision('decision_mype_anterior_remype');
                $validaciones[] = $this->validacion('decision_mype_anterior_remype', 'Régimen laboral por validar: ingreso anterior a la inscripción REMYPE',
                    "El trabajador ingresó el {$ingreso->format('d/m/Y')}, antes de la inscripción REMYPE ({$inscripcion->format('d/m/Y')}). ¿Qué régimen corresponde a su vínculo?",
                    [
                        ['value' => 'general_todo_el_vinculo', 'label' => 'Régimen general por todo el vínculo (conserva el régimen de su contratación)'],
                        ['value' => 'general_hasta_inscripcion', 'label' => 'Régimen general hasta la inscripción y MYPE desde entonces'],
                        ['value' => 'mantener_mype', 'label' => 'Régimen MYPE por todo el vínculo'],
                    ], 'Ley 32353 art. 42.2; criterio MTPE/SUNAFIL (fuente secundaria)', $elegido);
                if ($elegido === 'general_todo_el_vinculo') {
                    $tramos = $this->reemplazarMype($tramos, null);
                } elseif ($elegido === 'general_hasta_inscripcion') {
                    $tramos = $this->reemplazarMype($tramos, $inscripcion);
                }
            }
            $alCese = end($tramos)['regimen'] ?? null;
            if (in_array($alCese, self::MYPE, true) && in_array($empresa->regimen_laboral, self::LABORALES, true) && $empresa->regimen_laboral !== $alCese) {
                $elegido = $decision('decision_categoria_mype');
                $validaciones[] = $this->validacion('decision_categoria_mype', 'Régimen laboral por validar: categoría de la empresa',
                    "El vínculo termina en {$alCese}, pero la empresa figura como {$empresa->regimen_laboral}. Agento no guarda el historial de categorías. ¿Qué régimen rige al cese?",
                    [['value' => 'regimen_del_vinculo', 'label' => "El del vínculo ({$alCese})"], ['value' => 'categoria_de_la_empresa', 'label' => "El de la empresa ({$empresa->regimen_laboral})"]],
                    'Ley 32353 art. 45.2 (permanencia y cambio de categoría)', $elegido);
                if ($elegido === 'categoria_de_la_empresa') {
                    $ultimo = array_key_last($tramos);
                    $tramos[$ultimo] = [...$tramos[$ultimo], 'regimen' => $empresa->regimen_laboral, 'origen' => 'decision'];
                }
            }
        }

        $tramos = $this->unir($tramos);

        return [
            'tramos' => array_map(fn ($t) => [...$t, 'desde' => $t['desde']->toDateString(), 'hasta' => $t['hasta']->toDateString()], $tramos),
            'validaciones' => $validaciones,
            'alertas' => $alertas,
            'regimen_al_cese' => end($tramos)['regimen'] ?? null,
        ];
    }

    /** Tramos MYPE pasan a General (todos, o solo antes de $desde). */
    private function reemplazarMype(array $tramos, ?Carbon $desde): array
    {
        $resultado = [];
        foreach ($tramos as $t) {
            if (! in_array($t['regimen'], self::MYPE, true)) {
                $resultado[] = $t;

                continue;
            }
            if ($desde === null || $t['hasta']->lt($desde)) {
                $resultado[] = [...$t, 'regimen' => 'General', 'origen' => 'decision'];
            } elseif ($t['desde']->lt($desde)) {
                $resultado[] = [...$t, 'hasta' => $desde->copy()->subDay(), 'regimen' => 'General', 'origen' => 'decision'];
                $resultado[] = [...$t, 'desde' => $desde->copy()];
            } else {
                $resultado[] = $t;
            }
        }

        return $resultado;
    }

    private function unir(array $tramos): array
    {
        $unidos = [];
        foreach ($tramos as $t) {
            $ultimo = array_key_last($unidos);
            if ($ultimo !== null && $unidos[$ultimo]['regimen'] === $t['regimen'] && $unidos[$ultimo]['hasta']->copy()->addDay()->isSameDay($t['desde'])) {
                $unidos[$ultimo]['hasta'] = $t['hasta'];

                continue;
            }
            $unidos[] = $t;
        }

        return $unidos;
    }

    private function periodo(array $t): string
    {
        return $t['desde']->format('d/m/Y').'–'.$t['hasta']->format('d/m/Y');
    }

    private function opcionesRegimen(): array
    {
        return array_map(fn ($r) => ['value' => $r, 'label' => $r], self::LABORALES);
    }

    /** @return array<string, mixed> */
    private function validacion(string $clave, string $titulo, string $pregunta, array $opciones, string $fuente, ?string $decision): array
    {
        return [
            'clave' => $clave, 'titulo' => $titulo, 'pregunta' => $pregunta, 'opciones' => $opciones,
            'fuente' => $fuente, 'bloqueante' => true, 'decision' => $decision,
            'resuelta' => $decision !== null && in_array($decision, array_column($opciones, 'value'), true),
            'conceptos' => ['Todos los conceptos (régimen aplicable)'], 'diferencias' => [],
        ];
    }
}
