<?php

namespace App\Modules\Nominas\Application;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaOperacionService;
use App\Modules\Nominas\Domain\RegimenCalculatorFactory;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Configuracion\Models\ReglaDescuentoTardanza;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Nominas\Support\ProrateoIngresoTardio;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Orquesta el cálculo de UNA boleta para un colaborador dentro de un ciclo,
 * siguiendo el pseudocódigo de la Sección 2.14 del encargo:
 * asistencia + parámetros vigentes + motor del régimen → ingresos/egresos/
 * aportaciones → neto + snapshots de auditoría. No persiste nada —
 * BoletaService decide cómo guardar el resultado (transacción, versión).
 */
class CalcularBoletaColaborador
{
    public function __construct(private readonly AsistenciaOperacionService $asistenciaOperacion) {}

    /**
     * @param  int|null  $cicloId  Necesario para recoger comisiones/bonos del período
     *   (colaborador_conceptos_periodo) y para excluir la boleta del propio
     *   ciclo del histórico de gratificaciones/renta anual. Null solo en
     *   contexto de prueba manual sin ciclo real todavía creado.
     * @param  string|null  $fechaPago  V3 Fase 6F.2.3 — ciclo.fecha_pago,
     *   ÚNICA fecha que debe usarse para resolver la Remuneración Máxima
     *   Asegurable (RMA) de AFP_PRIMA_SEGURO (Fase 6F.2.2: la RMA está
     *   normada como "vigente a la fecha de pago", a diferencia de RMV/UIT/
     *   tasas, que siguen usando $fechaCorte sin cambios). Null solo en
     *   flujos sin ciclo real (previsualización) — en ese caso se usa
     *   $fechaCorte como estimación explícita, marcada en `alertas`, nunca
     *   de forma silenciosa.
     * @return array{
     *   regimen_laboral: string, sueldo_basico: float, dias_pagados: float,
     *   asistencia_procesada: bool, dias_falta: float, minutos_tardanza: int,
     *   ingresos: array<int, array>, egresos: array<int, array>, aportaciones: array<int, array>,
     *   total_ingresos: float, total_egresos: float, total_aportaciones: float, neto_a_pagar: float,
     *   snapshot_parametros_version: string, snapshot_reglas_version: string, alertas: array<int, string>,
     * }
     */
    public function calcular(Colaborador $colaborador, string $fechaInicio, string $fechaFin, string $fechaCorte, ?int $cicloId = null, ?string $fechaPago = null): array
    {
        $regimen = $colaborador->regimen_laboral ?: 'General';
        $calculadora = RegimenCalculatorFactory::paraRegimen($regimen);
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $regimen, $fechaCorte);

        $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaCorte)
            ->orderByDesc('vigencia_desde')
            ->orderByDesc('id')
            ->first();

        if (! $remuneracion) {
            throw new RuntimeException("El colaborador #{$colaborador->id} no tiene remuneración vigente a {$fechaCorte}.");
        }

        $sueldoBasico = (float) $remuneracion->salario;
        $alertas = [];

        // V3 P3/T1 — la condición de confianza SIEMPRE se resuelve por
        // fecha dentro de obtenerAsistenciaDelPeriodo() (nunca acá con un
        // solo booleano para todo el período): un cambio de condición a
        // mitad de mes solo debe neutralizar los días posteriores a su
        // vigencia real, nunca el mes completo retroactivamente.
        $asistencia = $this->obtenerAsistenciaDelPeriodo($colaborador, $fechaInicio, $fechaFin);

        $condicionVigenteCorte = ColaboradorCondicionLaboral::vigenteEn($colaborador->id, $fechaCorte);
        if ($condicionVigenteCorte?->es_trabajador_confianza) {
            // Los aportes obligatorios (AFP/ONP, EsSalud, renta 5ta) NO
            // cambian por esto — siguen calculándose normal más abajo,
            // dependen del sueldo básico completo, no de esta neutralización.
            $alertas[] = 'Trabajador de confianza — no se aplican descuentos por tardanza, horario desplazado, horas incompletas ni ingresos por horas extra en los días bajo esa condición; se paga el sueldo básico completo.';
        }

        if (! $asistencia['asistencia_procesada']) {
            // El básico/tardanza de este cálculo se basan en 0 faltas y 0
            // minutos de tardanza porque Asistencia todavía no procesó el
            // período — NO porque el colaborador tenga asistencia perfecta.
            // La UI debe mostrar "Sin procesar", nunca el número como si
            // fuera un resultado confirmado.
            $alertas[] = 'Asistencia del período aún no procesada — el cálculo asume 0 faltas y 0 minutos de tardanza hasta que se procese.';
        }
        $ingresos = [];
        $egresos = [];
        $aportaciones = [];

        // --- Ingresos ---
        $basico = $calculadora->calcularBasico($sueldoBasico, $asistencia['dias_falta'], $asistencia['horas_permiso_sin_goce']);
        $diasPagados = $basico['dias_pagados'];

        // calcularBasico() no conoce fecha_ingreso (solo recibe dias_falta,
        // que nunca incluye días previos al ingreso porque Asistencia ni
        // genera fila para esas fechas) — así que un colaborador que
        // ingresa a mitad de $fechaInicio cobraba el mes nominal completo.
        // Se muta $basico['linea'] explícitamente (no solo la variable
        // $diasPagados): si no, dias_pagados queda correcto en la boleta
        // pero el monto de la línea de ingreso sigue sin prorratear. No-op
        // para LiquidacionCeseService — ver ProrateoIngresoTardio.
        $diasNoPagadosPorIngresoTardio = ProrateoIngresoTardio::diasNoPagados($colaborador->fecha_ingreso, $fechaInicio);
        $diasPagados = max(0, $diasPagados - $diasNoPagadosPorIngresoTardio);
        if ($diasNoPagadosPorIngresoTardio > 0) {
            $basico['linea']['monto'] = round(($sueldoBasico / 30) * $diasPagados, 2);
            $basico['linea']['cantidad'] = $diasPagados;
            $basico['linea']['formula_texto'] = "({$sueldoBasico} / 30) × {$diasPagados} días pagados"
                ." — excluye {$diasNoPagadosPorIngresoTardio} día(s) previos al ingreso ({$colaborador->fecha_ingreso->toDateString()})";
        }

        // SUNAT distingue Remuneración Vacacional (Tabla 22: 0118) de
        // Remuneración/Jornal Básico (0121) — calcularBasico() ya paga el
        // mes completo incluyendo los días de vacaciones (no los descuenta
        // de dias_pagados, a diferencia de una falta), así que acá solo se
        // DESCOMPONE esa misma línea en dos, nunca se suma un monto nuevo
        // encima (evita duplicar sueldo). No se toca PlanillaDependienteCalculator.
        $vacaciones = $this->asistenciaOperacion->vacacionesTomadas($colaborador, $fechaInicio, $fechaFin);
        $diasVacaciones = min($vacaciones['dias'], $diasPagados);

        if ($diasVacaciones > 0) {
            $montoVacacional = round(($sueldoBasico / 30) * $diasVacaciones, 2);
            $ingresos[] = [
                ...$basico['linea'],
                'monto' => round($basico['linea']['monto'] - $montoVacacional, 2),
                'cantidad' => $diasPagados - $diasVacaciones,
                'formula_texto' => $basico['linea']['formula_texto']." — excluye {$diasVacaciones} día(s) de vacaciones tomadas (ver Remuneración Vacacional)",
            ];
            $ingresos[] = [
                'codigo' => 'REMUNERACION_VACACIONAL',
                'monto' => $montoVacacional,
                'base_utilizada' => $sueldoBasico,
                'tasa_aplicada' => null,
                'cantidad' => $diasVacaciones,
                'formula_texto' => "({$sueldoBasico} / 30) × {$diasVacaciones} día(s) de vacaciones tomadas en el período",
            ];
        } else {
            $ingresos[] = $basico['linea'];
        }

        foreach ($calculadora->calcularHorasExtra($sueldoBasico, $asistencia['horas_he25'], $asistencia['horas_he35'], $asistencia['horas_he100'], $parametros) as $linea) {
            $ingresos[] = $linea;
        }

        if ($asignacionFamiliar = $calculadora->calcularAsignacionFamiliar((bool) $colaborador->tiene_hijos_asignacion_familiar, $parametros)) {
            $ingresos[] = $asignacionFamiliar;
        }

        // Las comisiones/bonos/adelantos que RR.HH. registró para este
        // período (colaborador_conceptos_periodo) se enrutan según el TIPO
        // real del concepto en el catálogo — nunca se asume que todo lo
        // manual es un ingreso. Un adelanto de sueldo (tipo=egreso) debe
        // descontar, no sumar (Sección 47).
        $conceptosManuales = $this->conceptosDelPeriodo($colaborador, $cicloId);
        foreach ($conceptosManuales as $manual) {
            if ($manual['tipo'] === 'egreso') {
                $egresos[] = $manual['linea'];
            } else {
                $ingresos[] = $manual['linea'];
            }
        }

        $codigosIngreso = collect($ingresos)->pluck('codigo')->unique()->values();
        $catalogoIngresos = ConceptoRemuneracion::whereIn('codigo', $codigosIngreso)->get()->keyBy('codigo');

        $baseRemunerativa = $this->sumarPorFlag($ingresos, $catalogoIngresos, 'es_remunerativo_laboral');
        $baseAfectaRenta5ta = $this->sumarPorFlag($ingresos, $catalogoIngresos, 'afecta_renta_5ta');

        // --- Egresos ---
        // V3 Fase 6D/6F.1 — la tardanza se calcula ANTES de AFP/ONP (antes
        // ocurría al revés) porque ONP, Renta 5ta y (desde Fase 6F.1) AFP
        // necesitan consumir la base YA neta de tardanza — Informe SUNAT
        // N.° 004-2014-SUNAT/5D0000, Conclusión 1, para ONP/Renta 5ta, y el
        // Artículo 30 del TUO de la Ley del SPP (D.S. 054-97-EF) para AFP:
        // "toda vez que ésta debe estar integrada únicamente por los
        // importes que hubieren sido efectivamente percibidos".
        //
        // $asistencia['minutos_tardanza'] ya llega consolidado desde
        // obtenerAsistenciaDelPeriodo() (HD aprobado, contabilizar_tardanzas
        // = false y trabajador de confianza ya la neutralizan ahí) — por eso
        // $tardanza['monto'] es directamente la tardanza REMUNERATIVA final,
        // nunca los minutos brutos detectados por Asistencia.
        $reglasDescuentoTardanza = ReglaDescuentoTardanza::where('empresa_id', $colaborador->empresa_id)
            ->orderBy('orden')
            ->get(['minutos_desde', 'minutos_hasta', 'tipo', 'valor'])
            ->map(fn ($r) => $r->toArray())
            ->all();
        $tardanza = $calculadora->calcularDescuentoTardanza($sueldoBasico, $asistencia['minutos_tardanza'], $reglasDescuentoTardanza);
        $egresos[] = $tardanza;

        // V3 A10 — HI aprobado: el día se sigue pagando completo en
        // calcularBasico() (sí asistió), y este descuento proporcional por
        // los minutos de salida anticipada aprobados es lo que efectivamente
        // deja el pago equivalente a "solo las horas trabajadas". Mismo
        // divisor que la tardanza (sueldo/240/60) — no se inventa uno nuevo.
        //
        // V3 Fase 6A — a diferencia de DESCUENTO_TARDANZA (que sí tiene
        // codigo_plame configurado y por eso puede mostrarse siempre en
        // S/0.00 sin problema), este concepto todavía no tiene clasificación
        // PLAME homologada. calcularDescuentoHorasIncompletas() SIEMPRE
        // devuelve una línea (por diseño, no se toca esa fórmula) — pero acá
        // solo se agrega a egresos (y por tanto solo se persiste en
        // boleta_conceptos) cuando el monto es efectivamente > 0. Mismo
        // patrón ya usado en este método para asignación familiar/renta 5ta
        // (conceptos opcionales que solo se agregan si aplican), no una
        // abstracción nueva. Sin esta guarda, CADA boleta —tuviera HI o
        // no— quedaba con una fila S/0.00 sin codigo_plame, y
        // PlameValidator bloqueaba el .rem de absolutamente todas. El código
        // PLAME de este concepto sigue sin homologar (V3 Fase 6B) — eso NO
        // cambia acá, solo su efecto sobre las bases previsionales (6E.1).
        $horasIncompletas = $calculadora->calcularDescuentoHorasIncompletas($sueldoBasico, $asistencia['minutos_horas_incompletas_aprobadas']);
        if ((float) $horasIncompletas['monto'] > 0) {
            $egresos[] = $horasIncompletas;
        }

        // V3 Fase 6E.1/6F.1 — tardanza e HI remunerativas se calculan ANTES
        // de AFP/ONP (y se suman en un solo descuento) porque ONP, EsSalud,
        // Renta 5ta y (desde Fase 6F.1) AFP deben consumir la base ya neta de
        // AMBAS. Fundamento: Informe SUNAT 004-2014-SUNAT/5D0000 (ONP/Renta
        // 5ta, tardanza y permisos) + definición SUNAT de la base de EsSalud
        // como "remuneración DEVENGADA en el mes" (Fase 6E) + Artículo 30 del
        // TUO de la Ley del SPP — D.S. 054-97-EF (Fase 6F): la remuneración
        // asegurable es "el total de las rentas... percibidas en dinero" —
        // el mismo test de "percibido/devengado" que ya se aplicó a
        // ESSALUD/ONP/Quinta, ahora también a AFP. Un trabajador con
        // tardanza o HI aprobada no percibió ni devengó esos minutos, así
        // que tampoco forman parte de ninguna de las 4 bases.
        //
        // $asistencia['minutos_tardanza'] y
        // $asistencia['minutos_horas_incompletas_aprobadas'] ya llegan
        // consolidados desde obtenerAsistenciaDelPeriodo() (HD aprobado,
        // contabilizar_tardanzas=false y confianza ya los neutralizan ahí) —
        // por eso $tardanza['monto']/$horasIncompletas['monto'] son
        // directamente el descuento REMUNERATIVO final de cada uno, nunca
        // los minutos brutos detectados por Asistencia. HI pendiente o
        // rechazada ya llega en 0 desde ese mismo punto (V3 A2/T2, mismo
        // criterio que HE), por lo que nunca reduce estas bases.
        $descuentoRemunerativoAsistencia = $tardanza['monto'] + $horasIncompletas['monto'];
        $baseRemunerativaNetaAsistencia = max(0, $baseRemunerativa - $descuentoRemunerativoAsistencia);
        // Renta 5ta usa su propio flag (afecta_renta_5ta), distinto de
        // es_remunerativo_laboral — no necesariamente suman lo mismo (ej. un
        // concepto puede ser remunerativo pero no afecto a renta 5ta, o
        // viceversa), así que se resuelve como una base separada, no
        // reutilizando $baseRemunerativaNetaAsistencia.
        $baseAfectaRenta5taNetaAsistencia = max(0, $baseAfectaRenta5ta - $descuentoRemunerativoAsistencia);

        // V3 Fase 6F.1 — ONP y AFP ya usan la misma base neta de asistencia
        // (Art. 30 TUO SPP homologado en Fase 6F), así que la distinción por
        // sistema_previsional que existía desde la Fase 6D ya no aplica acá
        // — se retira en vez de mantenerla sin uso real.
        //
        // V3 Fase 6F.2.1 — la Remuneración Máxima Asegurable (RMA) SOLO se
        // resuelve para afiliados AFP: ONP nunca la usa (su rama ni siquiera
        // recibe el parámetro dentro de calcularAporteAfpOnp()), así que
        // consultarla únicamente en la rama AFP evita que la ausencia de
        // este parámetro bloquee a colaboradores ONP o cualquier otro flujo
        // que no la necesite (Fase 6F.2, Sección 14).
        //
        // V3 Fase 6F.2.3 — la RMA se resuelve por $fechaPago (ciclo.fecha_pago),
        // NUNCA por $fechaCorte — son fechas distintas a propósito (Fase
        // 6F.2.2). Una boleta real siempre trae $fechaPago (columna
        // required desde la creación del ciclo); solo la previsualización
        // sin ciclo (BoletaService::previsualizarPlanilla()) no la tiene,
        // y en ese caso se usa $fechaCorte como estimación EXPLÍCITA — vía
        // `alertas`, el mismo mecanismo ya usado para otras advertencias no
        // vinculantes de este método (ej. "asistencia aún no procesada") —
        // nunca de forma silenciosa.
        $fechaRma = $fechaPago ?? $fechaCorte;
        if ($colaborador->sistema_previsional !== 'onp' && $fechaPago === null) {
            $alertas[] = 'Fecha de pago no disponible (cálculo sin ciclo real) — la Remuneración Máxima Asegurable (RMA) de la prima AFP se estimó usando la fecha de corte de asistencia; el monto real puede variar si la fecha de pago efectiva cae en un trimestre distinto de RMA.';
        }
        $rmaAfp = $colaborador->sistema_previsional === 'onp'
            ? null
            : ParametrosVigentesResolver::rmaAfp($colaborador->empresa, $regimen, $fechaRma);

        foreach ($calculadora->calcularAporteAfpOnp($colaborador, $baseRemunerativaNetaAsistencia, $parametros, $fechaCorte, $rmaAfp) as $linea) {
            $egresos[] = $linea;
        }

        // V3 Fase 6D/6E.1 — misma lógica que ONP, con su propia base
        // (afecta_renta_5ta) ya neta de tardanza e HI: el Informe SUNAT
        // 004-2014-SUNAT/5D0000 cubre explícitamente Renta de 5ta junto con
        // ESSALUD/ONP. $baseAfectaRenta5taNetaAsistencia es el parámetro que
        // calcularRenta5ta() usa como "ingreso mensual afecto" tanto para el
        // mes en curso como para la proyección de gratificación — no hay
        // reconstrucción interna de la base que este cambio deje sin efecto.
        $renta5ta = $this->calcularRenta5ta($colaborador, $baseAfectaRenta5taNetaAsistencia, $parametros, $fechaCorte, $cicloId);
        if ($renta5ta) {
            $egresos[] = $renta5ta;
        }

        // --- Aportaciones ---
        // V3 Fase 6D/6E.1 — EsSalud ya restaba la tardanza desde antes; ahora
        // reutiliza $baseRemunerativaNetaAsistencia (tardanza + HI, calculada
        // una sola vez más arriba) en vez de repetir la resta acá.
        $essalud = $calculadora->calcularEsSalud($baseRemunerativaNetaAsistencia, $parametros, $colaborador->empresa->seguro_salud);
        $aportaciones[] = $essalud['linea'];
        if ($essalud['piso_activado']) {
            $alertas[] = 'Se aplicó el piso legal de EsSalud (9% de la RMV vigente) porque el cálculo sobre la base remunerativa fue menor.';
        }

        $gratificacionesSemestre = $this->gratificacionesPercibidasSemestre($colaborador, $fechaCorte, $cicloId);
        foreach ($calculadora->calcularProvisiones($baseRemunerativa, $gratificacionesSemestre, $parametros) as $linea) {
            $aportaciones[] = $linea;
        }

        $totalIngresos = round(collect($ingresos)->sum('monto'), 2);
        $totalEgresos = round(collect($egresos)->sum('monto'), 2);
        $totalAportaciones = round(collect($aportaciones)->sum('monto'), 2);

        return [
            'regimen_laboral' => $regimen,
            'sueldo_basico' => $sueldoBasico,
            'dias_pagados' => $diasPagados,
            'asistencia_procesada' => $asistencia['asistencia_procesada'],
            'dias_falta' => $asistencia['dias_falta'],
            'minutos_tardanza' => $asistencia['minutos_tardanza'],
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'aportaciones' => $aportaciones,
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'total_aportaciones' => $totalAportaciones,
            'neto_a_pagar' => round($totalIngresos - $totalEgresos, 2),
            'snapshot_parametros_version' => $parametros['version_id'],
            'snapshot_reglas_version' => 'planilla-dependiente-v1',
            'alertas' => $alertas,
        ];
    }

    /**
     * La clasificación remunerativo/renta-5ta SIEMPRE viene del catálogo
     * ConceptoRemuneracion — nunca de una lista hardcodeada acá (Sección 18).
     */
    private function sumarPorFlag(array $lineas, $catalogo, string $flag): float
    {
        return round(collect($lineas)->sum(function (array $linea) use ($catalogo, $flag) {
            $concepto = $catalogo->get($linea['codigo']);

            return ($concepto && $concepto->{$flag}) ? $linea['monto'] : 0;
        }), 2);
    }

    /**
     * Comisiones/bonos que RR.HH. ingresó para este colaborador+ciclo antes
     * de calcular (colaborador_conceptos_periodo, Sección 46).
     *
     * @return array<int, array{tipo: string, linea: array}>
     */
    private function conceptosDelPeriodo(Colaborador $colaborador, ?int $cicloId): array
    {
        if (! $cicloId) {
            return [];
        }

        return ColaboradorConceptoPeriodo::where('ciclo_id', $cicloId)
            ->where('colaborador_id', $colaborador->id)
            ->with('concepto')
            ->get()
            ->map(fn (ColaboradorConceptoPeriodo $item) => [
                'tipo' => $item->concepto->tipo,
                'linea' => [
                    'codigo' => $item->concepto->codigo,
                    'monto' => (float) $item->monto,
                    'base_utilizada' => null,
                    'tasa_aplicada' => null,
                    'cantidad' => null,
                    'formula_texto' => 'Monto ingresado por RR.HH. para este período'.($item->motivo ? " — {$item->motivo}" : ''),
                    // Si RR.HH. eligió una clasificación PLAME concreta
                    // (BONIFICACION/BONO_NO_REMUNERATIVO genéricos no la
                    // tienen por defecto), el snapshot debe usar ESE código,
                    // no el del concepto motor.
                    'concepto_definicion_id' => $item->concepto_definicion_id,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Renta de 5ta — proyección simplificada (Sección 2.11): NO reconstruye
     * ingresos de un empleador anterior en el mismo año ni proyecta
     * gratificaciones futuras con precisión; usa el histórico de boletas
     * propias del año en curso y una proyección plana de gratificación si el
     * régimen la tiene. Documentado como PENDIENTE FUNCIONAL para la
     * liquidación por cese (que sí necesita la lista completa de conceptos
     * inafectos del art. 18° LIR, distinta de esta).
     */
    private function calcularRenta5ta(Colaborador $colaborador, float $ingresoMensualAfectoRenta5ta, array $parametros, string $fechaCorte, ?int $cicloId): ?array
    {
        if (empty($parametros['tramos_renta_5ta'])) {
            return null;
        }

        $fecha = Carbon::parse($fechaCorte);
        $mesesRestantes = 13 - $fecha->month; // incluye el mes actual

        $ingresosYaPercibidos = BoletaConcepto::whereHas('boleta', function ($q) use ($colaborador, $fecha, $cicloId) {
            $q->where('colaborador_id', $colaborador->id)
                ->where('es_version_vigente', true)
                ->whereYear('calculado_at', $fecha->year)
                ->when($cicloId, fn ($q2) => $q2->where('ciclo_id', '!=', $cicloId));
        })
            ->where('afecta_renta_5ta', true)
            ->sum('monto');

        $gratificacionProyectada = $parametros['tasa_gratificacion'] > 0
            ? round(($ingresoMensualAfectoRenta5ta / 6) * $parametros['tasa_gratificacion'] * 2, 2)
            : 0.0;

        $ingresoAnualProyectado = (float) $ingresosYaPercibidos
            + ($ingresoMensualAfectoRenta5ta * $mesesRestantes)
            + $gratificacionProyectada;

        $deduccion = $parametros['deduccion_5ta_uit'] * $parametros['uit'];
        $rentaNetaAnual = max(0, $ingresoAnualProyectado - $deduccion);

        $impuestoAnual = 0.0;
        foreach ($parametros['tramos_renta_5ta'] as $tramo) {
            $limiteInferior = $tramo['limite_inferior_uit'] * $parametros['uit'];
            $limiteSuperior = $tramo['limite_superior_uit'] !== null ? $tramo['limite_superior_uit'] * $parametros['uit'] : null;

            if ($rentaNetaAnual <= $limiteInferior) {
                continue;
            }

            $baseTramo = $limiteSuperior !== null ? min($rentaNetaAnual, $limiteSuperior) - $limiteInferior : $rentaNetaAnual - $limiteInferior;
            $impuestoAnual += $baseTramo * $tramo['tasa'];
        }

        if ($impuestoAnual <= 0) {
            return null;
        }

        $retencionMensual = round($impuestoAnual / $mesesRestantes, 2);

        return [
            'codigo' => 'RENTA_5TA',
            'monto' => $retencionMensual,
            'base_utilizada' => round($rentaNetaAnual, 2),
            'tasa_aplicada' => null,
            'cantidad' => null,
            'formula_texto' => "Proyección anual {$ingresoAnualProyectado} − {$deduccion} (deducción {$parametros['deduccion_5ta_uit']} UIT) = {$rentaNetaAnual} renta neta → impuesto anual {$impuestoAnual} / {$mesesRestantes} meses restantes",
        ];
    }

    private function gratificacionesPercibidasSemestre(Colaborador $colaborador, string $fechaCorte, ?int $cicloId): float
    {
        $fecha = Carbon::parse($fechaCorte);
        $inicioSemestre = $fecha->month <= 6 ? $fecha->copy()->startOfYear() : Carbon::create($fecha->year, 7, 1);

        $conceptoId = ConceptoRemuneracion::where('codigo', 'GRATIFICACION_LEGAL')->value('id');
        if (! $conceptoId) {
            return 0.0;
        }

        return (float) BoletaConcepto::where('concepto_id', $conceptoId)
            ->whereHas('boleta', function ($q) use ($colaborador, $inicioSemestre, $fecha, $cicloId) {
                $q->where('colaborador_id', $colaborador->id)
                    ->where('es_version_vigente', true)
                    ->whereDate('calculado_at', '>=', $inicioSemestre->toDateString())
                    ->whereDate('calculado_at', '<=', $fecha->toDateString())
                    ->when($cicloId, fn ($q2) => $q2->where('ciclo_id', '!=', $cicloId));
            })
            ->sum('monto');
    }

    /**
     * @return array{dias_falta: float, horas_permiso_sin_goce: float, minutos_tardanza: int, horas_he25: float, horas_he35: float, horas_he100: float}
     */
    private function obtenerAsistenciaDelPeriodo(Colaborador $colaborador, string $fechaInicio, string $fechaFin): array
    {
        $resultados = AsistenciaResultadoDiario::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->get();

        $this->verificarRolRotativoCompleto($colaborador, $fechaInicio, $fechaFin, $resultados);

        // V3 P3/T1 — días donde el colaborador fue trabajador de confianza
        // según su HISTORIAL real (nunca colaborador.es_trabajador_confianza
        // actual) quedan fuera de todo efecto remunerativo de asistencia
        // (falta/tardanza/HD/HI/HE) — pero siguen contando como día pagado
        // (no se excluyen de dias_pagados, solo de los descuentos/ingresos
        // derivados de asistencia). Respeta un cambio de condición a mitad
        // de período: solo los días bajo la vigencia confianza se filtran.
        $fechasConfianza = $this->fechasConCondicionLaboral(
            $colaborador, $fechaInicio, $fechaFin, fn (ColaboradorCondicionLaboral $c) => (bool) $c->es_trabajador_confianza
        );
        $resultadosControlados = $fechasConfianza->isEmpty()
            ? $resultados
            : $resultados->reject(fn (AsistenciaResultadoDiario $r) => $fechasConfianza->contains($r->fecha->toDateString()));

        // V3 P2/T1 — "contabilizar_tardanzas" resuelto por HISTORIAL (nunca
        // el valor actual), mismo criterio que confianza: un cambio a mitad
        // de mes solo afecta los días posteriores a su vigencia. Por
        // defecto (fila sin valor, ej. históricos previos a esta migración)
        // se asume true — NO excluir — igual que el default real de la
        // columna en `colaboradores` (default true).
        $fechasSinContabilizarTardanzas = $this->fechasConCondicionLaboral(
            $colaborador, $fechaInicio, $fechaFin,
            fn (ColaboradorCondicionLaboral $c) => $c->contabilizar_tardanzas === false
        );

        // Permisos sin goce NO se neutralizan por confianza — es una
        // decisión funcional explícita (V3 Sección 31): la condición solo
        // cubre falta/tardanza/HD/HI/HE, nunca una licencia formal.
        $horasPermisoSinGoce = AsistenciaPermiso::where('colaborador_id', $colaborador->id)
            ->where('estado', 'aprobado')
            ->where('con_goce', false)
            ->where('fecha_inicio', '<=', $fechaFin)
            ->where('fecha_fin', '>=', $fechaInicio)
            ->get()
            ->sum(function (AsistenciaPermiso $permiso) use ($fechaInicio, $fechaFin) {
                $desde = $permiso->fecha_inicio->max(Carbon::parse($fechaInicio));
                $hasta = $permiso->fecha_fin->min(Carbon::parse($fechaFin));

                return max(0, $desde->diffInDays($hasta) + 1) * 8;
            });

        // Las horas extra NUNCA se leen de AsistenciaResultadoDiario.minutos_extra_*
        // para efectos de pago: esas columnas son la detección automática de
        // ProcesarAsistenciaDiaria (dato operativo para que Asistencia muestre
        // "posible HE"), no una aprobación. La única fuente de verdad para
        // Nómina es AsistenciaHoraExtra.estado='aprobado' con sus
        // minutos_aprobados — pendientes y rechazadas pagan 0 (V3 A2/T2).
        $minutosHeAprobados = AsistenciaHoraExtra::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->where('estado', 'aprobado')
            ->when($fechasConfianza->isNotEmpty(), fn ($q) => $q->whereNotIn('fecha', $fechasConfianza->all()))
            ->selectRaw('tasa, SUM(minutos_aprobados) as minutos')
            ->groupBy('tasa')
            ->pluck('minutos', 'tasa');

        // HD/HI (V3 A7/A9/A10): igual que con HE, el dato DETECTADO
        // (minutos_tardanza / minutos_salida_anticipada en el resultado
        // diario) nunca se usa directo para pagar — solo la DECISIÓN de
        // RR.HH. sobre la incidencia (AsistenciaIncidencia.estado) decide el
        // efecto remunerativo final. Pendiente o rechazada = sin efecto
        // especial (se mantiene el comportamiento de hoy).
        $incidenciasHdHi = AsistenciaIncidencia::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->whereIn('tipo', [AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO, AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS])
            ->where('estado', AsistenciaIncidencia::ESTADO_RESUELTA)
            ->when($fechasConfianza->isNotEmpty(), fn ($q) => $q->whereNotIn('fecha', $fechasConfianza->all()))
            ->get(['resultado_diario_id', 'tipo']);
        $resultadosHdAprobado = $incidenciasHdHi->where('tipo', AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO)
            ->pluck('resultado_diario_id')->flip();
        $resultadosHiAprobado = $incidenciasHdHi->where('tipo', AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS)
            ->pluck('resultado_diario_id')->flip();

        // HD aprobado: la tardanza detectada ese día deja de descontarse
        // ("presente sin tardanza para nómina" — el dato bruto en
        // AsistenciaResultadoDiario NO se toca, solo se excluye acá).
        // Prioridad (V3 Sección 21): HD aprobado siempre gana — si el día
        // ya no genera descuento por HD, la regla de contabilizar_tardanzas
        // no tiene nada que anular encima (no hay doble negación, solo se
        // evalúa la condición extra cuando HD no aplicó).
        $minutosTardanza = (int) $resultadosControlados->sum(function (AsistenciaResultadoDiario $r) use ($resultadosHdAprobado, $fechasSinContabilizarTardanzas) {
            if ($resultadosHdAprobado->has($r->id)) {
                return 0;
            }
            if ($fechasSinContabilizarTardanzas->contains($r->fecha->toDateString())) {
                return 0;
            }

            return $r->minutos_tardanza;
        });
        // HI aprobado: los minutos de salida anticipada de ESE día entran al
        // descuento proporcional (Sección PlanillaDependienteCalculator).
        // No se solapan con la tardanza: cada uno cubre un extremo distinto
        // de la jornada (llegada tarde vs. salida temprana).
        $minutosHorasIncompletasAprobadas = (int) $resultadosControlados->sum(
            fn (AsistenciaResultadoDiario $r) => $resultadosHiAprobado->has($r->id) ? $r->minutos_salida_anticipada : 0
        );

        return [
            'asistencia_procesada' => $resultados->isNotEmpty(),
            'dias_falta' => (float) $resultadosControlados->where('estado', 'falta')->count(),
            'horas_permiso_sin_goce' => (float) $horasPermisoSinGoce,
            'minutos_tardanza' => $minutosTardanza,
            'minutos_horas_incompletas_aprobadas' => $minutosHorasIncompletasAprobadas,
            'horas_he25' => round((float) ($minutosHeAprobados['25'] ?? 0) / 60, 2),
            'horas_he35' => round((float) ($minutosHeAprobados['35'] ?? 0) / 60, 2),
            'horas_he100' => round((float) ($minutosHeAprobados['100'] ?? 0) / 60, 2),
        ];
    }

    /**
     * V3 P3/T1/P2 — reconstruye, día por día dentro del rango, en cuáles el
     * colaborador cumplía una condición laboral según su HISTORIAL real
     * (ColaboradorCondicionLaboral, append-only por vigencia_desde) — nunca
     * el valor mutable actual del colaborador. Necesario para que un cambio
     * de condición a mitad de período (Sección 25 del encargo P3, mismo
     * criterio aplicado a "contabilizar_tardanzas" en P2) solo afecte los
     * días posteriores a su vigencia, nunca el período completo.
     *
     * Genérico por predicado (en vez de duplicar este recorrido para cada
     * campo histórico que necesite esta misma reconstrucción por fecha) —
     * hoy lo usan trabajador de confianza y contabilizar_tardanzas.
     *
     * @param  callable(ColaboradorCondicionLaboral): bool  $predicado
     * @return Collection<int, string> fechas Y-m-d
     */
    private function fechasConCondicionLaboral(Colaborador $colaborador, string $fechaInicio, string $fechaFin, callable $predicado): Collection
    {
        $condiciones = ColaboradorCondicionLaboral::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaFin)
            ->orderBy('vigencia_desde')
            ->orderBy('id')
            ->get(['es_trabajador_confianza', 'contabilizar_tardanzas', 'vigencia_desde']);

        if ($condiciones->isEmpty()) {
            return collect();
        }

        $fechas = collect();
        $inicioPeriodo = Carbon::parse($fechaInicio);
        $finPeriodo = Carbon::parse($fechaFin);

        foreach ($condiciones as $indice => $condicion) {
            if (! $predicado($condicion)) {
                continue;
            }

            $desde = $condicion->vigencia_desde->max($inicioPeriodo);
            $siguiente = $condiciones->get($indice + 1);
            $hasta = $siguiente ? $siguiente->vigencia_desde->copy()->subDay()->min($finPeriodo) : $finPeriodo;

            if ($desde->gt($hasta)) {
                continue;
            }

            for ($fecha = $desde->copy(); $fecha->lte($hasta); $fecha->addDay()) {
                $fechas->push($fecha->toDateString());
            }
        }

        return $fechas;
    }

    /**
     * Un horario rotativo nunca calcula planilla con días sin clasificar —
     * si falta declarar el rol de algún día del período, se rechaza el
     * cálculo completo de este colaborador en vez de omitirlo en silencio
     * (Sección: rotativos, cero inferencia — un día sin rol no es lo mismo
     * que un día sin marcaciones).
     *
     * V3 Rotativo Fase 1 — antes esto se detectaba por AUSENCIA de fila en
     * AsistenciaResultadoDiario (ProcesarAsistenciaDiaria abortaba antes de
     * persistir un día "sin_rol_definido"). Ahora ProcesarAsistenciaDiaria
     * SIEMPRE persiste un resultado (con
     * estado=AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR cuando no hay
     * planificación), así que la señal correcta es ese estado, no la
     * ausencia de la fila. Se mantiene esta verificación como defensa en
     * profundidad (el gate de enviar_nomina en AsistenciaPeriodoService ya
     * bloquea el envío con incidencias pendientes, pero un cálculo/
     * recálculo de boleta puede dispararse sobre un ciclo aún no cerrado).
     */
    private function verificarRolRotativoCompleto(Colaborador $colaborador, string $fechaInicio, string $fechaFin, $resultados): void
    {
        $esRotativo = ColaboradorHorarioAsignacion::where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fechaFin)
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $fechaInicio))
            ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo'))
            ->exists();

        if (! $esRotativo) {
            return;
        }

        $desde = Carbon::parse(max($fechaInicio, $colaborador->fecha_ingreso->toDateString()));
        $hasta = Carbon::parse($fechaFin);
        if ($desde->gt($hasta)) {
            return;
        }

        $resultadosPorFecha = $resultados->keyBy(fn ($r) => $r->fecha->toDateString());

        for ($fecha = $desde->copy(); $fecha->lte($hasta); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            $resultado = $resultadosPorFecha->get($fechaTexto);
            if ($resultado === null || $resultado->estado === AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR) {
                throw new RuntimeException(
                    "Tiene horario rotativo y le falta declarar el rol de turnos del {$fechaTexto} — no se puede calcular su planilla hasta que se complete.",
                );
            }
        }
    }
}
