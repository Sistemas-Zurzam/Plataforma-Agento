<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\HorarioDia;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProcesarAsistenciaDiaria
{
    public function __construct(
        private readonly ResolverJornadaDiaria $resolverJornada,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    public function procesar(Colaborador $colaborador, Carbon $fecha): AsistenciaResultadoDiario
    {
        $fecha = $fecha->copy()->startOfDay();
        $periodoProtegido = AsistenciaPeriodo::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->whereIn('estado', ['cerrado', 'enviado_nomina'])
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->exists();
        abort_if($periodoProtegido, 422, 'La fecha pertenece a un período de asistencia protegido.');

        // V3 P3 — se resuelve la condición vigente EN ESA FECHA (histórico,
        // nunca colaborador.es_trabajador_confianza actual) antes de tocar
        // horario/rol — un trabajador de confianza puede no tener horario
        // asignado y nunca debe fallar por eso. CalcularBoletaColaborador
        // vuelve a resolver esta misma condición por su cuenta al calcular
        // la boleta (no confía ciegamente en lo que quedó guardado acá).
        $condicionVigente = ColaboradorCondicionLaboral::vigenteEn($colaborador->id, $fecha->toDateString());
        if ($condicionVigente?->es_trabajador_confianza) {
            return $this->procesarNeutralConfianza($colaborador, $fecha);
        }

        $jornada = $this->resolverJornada->resolver($colaborador, $fecha);
        $horarioDia = $jornada['horario_dia'];
        [$inicioProgramado, $finProgramado] = $this->limitesProgramados($fecha, $horarioDia);
        $marcaciones = $this->obtenerMarcaciones($colaborador, $fecha, $inicioProgramado, $finProgramado, (bool) $horarioDia?->jornada_nocturna);

        $entrada = $marcaciones->first()?->marcado_at;
        $salida = $marcaciones->count() > 1 ? $marcaciones->last()?->marcado_at : null;
        $esLaborable = in_array($jornada['tipo_dia'], ['laborable_presencial', 'home_office'], true);
        $permiso = AsistenciaPermiso::query()->where('empresa_id', $colaborador->empresa_id)
            ->where('colaborador_id', $colaborador->id)->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())->first();
        // Rotativo Fase 1 — un rotativo sin planificación NUNCA se adivina
        // como laborable ni descanso, tenga o no marcaciones (Sección 2/12/
        // 13 del encargo): el permiso real sigue teniendo prioridad (ya
        // resuelve la ambigüedad, no hace falta pedir clasificación), pero
        // si no hay permiso, el día queda pendiente de clasificación en vez
        // de pasar por resolverEstado() — que de otro modo, con marcaciones
        // presentes, lo convertiría en 'presente'/'marcacion_incompleta'
        // sin que nadie lo haya decidido.
        $estadoBase = match (true) {
            (bool) $permiso && $marcaciones->isEmpty() => 'permiso',
            $jornada['tipo_dia'] === 'sin_rol_definido' => AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR,
            default => $this->resolverEstado($jornada['tipo_dia'], $marcaciones->count()),
        };

        $minutosProgramados = $esLaborable
            ? $this->minutosProgramados($inicioProgramado, $finProgramado, $fecha, $horarioDia)
            : 0;
        $minutosTrabajados = $entrada && $salida
            ? (int) max(0, $entrada->diffInMinutes($salida) - $this->minutosRefrigerio($fecha, $horarioDia))
            : 0;
        $tolerancia = $colaborador->tolerancia_particular_minutos
            ?? $jornada['asignacion']?->horario?->tolerancia_minutos
            ?? 0;
        $tardanza = ! $permiso && $entrada && $inicioProgramado && $esLaborable
            ? (int) max(0, $inicioProgramado->diffInMinutes($entrada, false) - $tolerancia)
            : 0;
        $salidaAnticipada = ! $permiso && $salida && $finProgramado && $esLaborable
            ? (int) max(0, $salida->diffInMinutes($finProgramado, false))
            : 0;

        // HD/HI solo refinan el caso "presente" (2+ marcaciones) — falta,
        // marcación incompleta, permiso y descanso/feriado no se tocan.
        // Requiere tardanza/salida anticipada/minutos ya calculados arriba,
        // por eso el estado final se resuelve acá y no antes (Sección V3 A7/A9).
        $estado = $estadoBase === 'presente'
            ? $this->refinarEstadoPresente($tardanza, $salidaAnticipada, $minutosTrabajados, $minutosProgramados)
            : $estadoBase;

        // Rotativo Fase 1 — un día "sin_rol_definido" nunca debe generar
        // horas extra observadas: no tiene minutos_programados de
        // referencia (0, porque no es laborable ni home_office) y aún no
        // se sabe si esas horas trabajadas corresponden a un día laboral o
        // a un descanso trabajado — cero impacto financiero hasta que
        // RR.HH. lo clasifique (Sección 11 del encargo).
        $extraObservada = ($entrada && $salida && $jornada['tipo_dia'] !== 'sin_rol_definido')
            ? $this->minutosExtraObservados($jornada['tipo_dia'], $minutosTrabajados, $minutosProgramados, (bool) $colaborador->contabilizar_horas_extra)
            : 0;
        $esDiaDescanso = in_array($jornada['tipo_dia'], ['descanso', 'feriado'], true);

        return DB::transaction(function () use (
            $colaborador, $fecha, $jornada, $estado, $entrada, $salida,
            $minutosProgramados, $minutosTrabajados, $tardanza, $salidaAnticipada,
            $extraObservada, $esDiaDescanso, $marcaciones
        ) {
            $periodo = AsistenciaPeriodo::query()
                ->where('empresa_id', $colaborador->empresa_id)
                ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
                ->whereDate('fecha_fin', '>=', $fecha->toDateString())
                ->first();

            $resultado = AsistenciaResultadoDiario::query()->updateOrCreate(
                [
                    'empresa_id' => $colaborador->empresa_id,
                    'colaborador_id' => $colaborador->id,
                    'fecha' => $fecha->toDateString(),
                ],
                [
                    'periodo_id' => $periodo?->id,
                    'horario_asignacion_id' => $jornada['asignacion']?->id,
                    'tipo_dia' => $jornada['tipo_dia'],
                    'estado' => $estado,
                    'entrada_at' => $entrada,
                    'salida_at' => $salida,
                    'minutos_programados' => $minutosProgramados,
                    'minutos_trabajados' => $minutosTrabajados,
                    'minutos_tardanza' => $tardanza,
                    'minutos_salida_anticipada' => $salidaAnticipada,
                    'minutos_extra_observados' => $extraObservada,
                    'minutos_extra_25' => $esDiaDescanso ? 0 : min(120, $extraObservada),
                    'minutos_extra_35' => $esDiaDescanso ? 0 : max(0, $extraObservada - 120),
                    'minutos_extra_100' => $esDiaDescanso ? $extraObservada : 0,
                    'procesado_at' => now(),
                ]
            );

            $resultado->marcaciones()->sync($marcaciones->modelKeys());
            $this->sincronizarIncidencia($resultado, $estado);
            // Fase 3.1 — ortogonal al $estado de arriba: 'presente' con
            // tipo_dia='descanso' sigue siendo 'presente' (nunca se
            // reemplaza), pero además queda marcado explícitamente como
            // trabajo sobre un descanso planificado. Nunca aplica a
            // 'feriado' (Sección 36/37 del encargo — backlog, no mezclar
            // todavía) ni al camino de confianza (procesarNeutralConfianza
            // no la llama).
            $this->sincronizarTrabajoEnDescanso($resultado, $jornada['tipo_dia'] === 'descanso' && $minutosTrabajados > 0);
            $this->sincronizarHorasExtra($resultado);

            return $resultado->load('marcaciones', 'incidencias');
        });
    }

    /**
     * V3 P3 — camino neutral para trabajador de confianza: NUNCA resuelve
     * horario/rol/jornada (puede no tener horario_id), nunca genera falta,
     * marcación incompleta, tardanza, HD, HI ni horas extra. Las
     * marcaciones reales (si las hay, vía biométrico) SÍ se conservan y
     * asocian — es registro de presencia informativo, nunca control
     * remunerativo (Sección 15 del encargo). El resultado sigue
     * guardándose (no se deja el día sin procesar) para que calendario y
     * reportes tengan algo que mostrar, con `estado='presente'` (valor ya
     * existente, no se inventa uno nuevo) y todos los campos derivados en
     * cero — CalcularBoletaColaborador nunca lee este resultado para pagar
     * de todas formas, vuelve a resolver la condición por fecha con su
     * propia fuente (ColaboradorCondicionLaboral), así que este resultado
     * es solo informativo/de calendario.
     */
    private function procesarNeutralConfianza(Colaborador $colaborador, Carbon $fecha): AsistenciaResultadoDiario
    {
        $marcaciones = $this->obtenerMarcaciones($colaborador, $fecha, null, null, false);
        $entrada = $marcaciones->first()?->marcado_at;
        $salida = $marcaciones->count() > 1 ? $marcaciones->last()?->marcado_at : null;

        return DB::transaction(function () use ($colaborador, $fecha, $marcaciones, $entrada, $salida) {
            $periodo = AsistenciaPeriodo::query()
                ->where('empresa_id', $colaborador->empresa_id)
                ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
                ->whereDate('fecha_fin', '>=', $fecha->toDateString())
                ->first();

            $resultado = AsistenciaResultadoDiario::query()->updateOrCreate(
                [
                    'empresa_id' => $colaborador->empresa_id,
                    'colaborador_id' => $colaborador->id,
                    'fecha' => $fecha->toDateString(),
                ],
                [
                    'periodo_id' => $periodo?->id,
                    'horario_asignacion_id' => null,
                    'tipo_dia' => 'no_sujeto_control',
                    'estado' => 'presente',
                    'entrada_at' => $entrada,
                    'salida_at' => $salida,
                    'minutos_programados' => 0,
                    'minutos_trabajados' => 0,
                    'minutos_tardanza' => 0,
                    'minutos_salida_anticipada' => 0,
                    'minutos_extra_observados' => 0,
                    'minutos_extra_25' => 0,
                    'minutos_extra_35' => 0,
                    'minutos_extra_100' => 0,
                    'procesado_at' => now(),
                ]
            );

            $resultado->marcaciones()->sync($marcaciones->modelKeys());
            // Reutiliza el mismo saneamiento con auditoría: si el día tenía
            // una incidencia/HE automática pendiente de ANTES de pasar a
            // confianza, queda obsoleta y se limpia con rastro (nunca borra
            // una ya resuelta/rechazada).
            $this->sincronizarIncidencia($resultado, 'presente');
            $this->sincronizarHorasExtra($resultado);

            return $resultado->load('marcaciones', 'incidencias');
        });
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function limitesProgramados(Carbon $fecha, ?HorarioDia $dia): array
    {
        if (! $dia?->hora_entrada || ! $dia->hora_salida) {
            return [null, null];
        }

        $inicio = Carbon::parse($fecha->toDateString().' '.$dia->hora_entrada);
        $fin = Carbon::parse($fecha->toDateString().' '.$dia->hora_salida);
        if ($dia->jornada_nocturna || $fin->lte($inicio)) {
            $fin->addDay();
        }

        return [$inicio, $fin];
    }

    private function obtenerMarcaciones(
        Colaborador $colaborador,
        Carbon $fecha,
        ?Carbon $inicio,
        ?Carbon $fin,
        bool $jornadaNocturna
    ) {
        $desde = $inicio?->copy()->subHours(6) ?? $fecha->copy()->startOfDay();
        $hasta = $fin?->copy()->addHours(6) ?? $fecha->copy()->endOfDay();
        if (! $jornadaNocturna) {
            // Sin jornada nocturna, ninguna marcación del turno siguiente debe
            // poder sustituir la salida de hoy — protege contra un
            // finProgramado corrido a mañana (p. ej. hora_salida mal
            // configurada, <= hora_entrada) que de otro modo "roba" la
            // marcación de entrada del día siguiente como si fuera la salida
            // de hoy, inflando horas extra/tardanza de forma masiva.
            $hasta = $hasta->minimum($fecha->copy()->endOfDay());
        }

        return AsistenciaMarcacion::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->whereNull('anulada_at')
            ->where(function ($query) use ($colaborador) {
                $query->where('colaborador_id', $colaborador->id)
                    ->orWhere('person_id', $colaborador->numero_documento);
            })
            ->whereBetween('marcado_at', [$desde, $hasta])
            ->orderBy('marcado_at')
            ->get();
    }

    private function resolverEstado(string $tipoDia, int $cantidadMarcaciones): string
    {
        if ($cantidadMarcaciones === 1) {
            return 'marcacion_incompleta';
        }
        if ($cantidadMarcaciones >= 2) {
            return 'presente';
        }
        if ($tipoDia === 'home_office') {
            return 'home_office';
        }
        if ($tipoDia === 'laborable_presencial') {
            return 'falta';
        }

        return $tipoDia;
    }

    /**
     * V3 A7/A9 — dentro del caso "presente" (2+ marcaciones), distingue si
     * el día es realmente normal o si corresponde a una de las dos nuevas
     * incidencias automáticas:
     *
     * HI (horas incompletas) tiene prioridad sobre HD: si la persona se
     * retiró antes de lo programado, sus horas quedan incompletas sin
     * importar si también llegó tarde — HD solo aplica cuando el
     * desplazamiento de horario NO dejó ningún déficit real de horas
     * trabajadas (llegó tarde pero se quedó para compensarlo).
     *
     * Ambas reutilizan exactamente los minutos ya calculados por este mismo
     * método (tardanza, salida anticipada, trabajados, programados) — nunca
     * se recalculan por separado, así heredan automáticamente el manejo de
     * refrigerio, jornada nocturna y horario variable por día ya resuelto
     * más arriba.
     */
    private function refinarEstadoPresente(int $tardanza, int $salidaAnticipada, int $minutosTrabajados, int $minutosProgramados): string
    {
        if ($salidaAnticipada > 0) {
            return AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS;
        }
        if ($tardanza > 0 && $minutosTrabajados >= $minutosProgramados) {
            return AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO;
        }

        return 'presente';
    }

    private function minutosProgramados(?Carbon $inicio, ?Carbon $fin, Carbon $fecha, ?HorarioDia $dia): int
    {
        if (! $inicio || ! $fin) {
            return 0;
        }

        return (int) max(0, $inicio->diffInMinutes($fin) - $this->minutosRefrigerio($fecha, $dia));
    }

    private function minutosRefrigerio(Carbon $fecha, ?HorarioDia $dia): int
    {
        if (! $dia?->refrigerio_inicio || ! $dia->refrigerio_fin) {
            return 0;
        }

        $inicio = Carbon::parse($fecha->toDateString().' '.$dia->refrigerio_inicio);
        $fin = Carbon::parse($fecha->toDateString().' '.$dia->refrigerio_fin);

        return $fin->gt($inicio) ? (int) $inicio->diffInMinutes($fin) : 0;
    }

    /**
     * "Permitir horas extra" dejó de ser un flag por día de horario
     * (horario_dias.permitir_horas_extra) y pasó a ser una condición del
     * propio colaborador (colaboradores.contabilizar_horas_extra) — mismo
     * campo que ya existía desde Personas, ahora sí conectado al cálculo
     * real en vez de quedar huérfano.
     */
    private function minutosExtraObservados(
        string $tipoDia,
        int $trabajados,
        int $programados,
        bool $contabilizarHorasExtra
    ): int {
        if (in_array($tipoDia, ['descanso', 'feriado'], true)) {
            return $trabajados;
        }
        if (! $contabilizarHorasExtra) {
            return 0;
        }

        return max(0, $trabajados - $programados);
    }

    /**
     * V3 A5/A29/A30 — extendido de 2 a 4 tipos automáticos (+ HD/HI). Reglas:
     *
     * 1. Cualquier incidencia automática PENDIENTE de un tipo que ya no
     *    corresponde a esta jornada reprocesada queda obsoleta y se elimina
     *    — pero ahora deja rastro de auditoría antes de borrarla (hueco
     *    detectado en la auditoría V3: antes se eliminaba en silencio).
     * 2. Una incidencia ya resuelta o rechazada NUNCA se toca acá: es una
     *    decisión de RR.HH. ya tomada, y reprocesar el día (p. ej. con el
     *    botón "Reprocesar" sin cambiar marcaciones) no debe revertirla a
     *    pendiente ni pisar su motivo_resolucion.
     */
    /**
     * Se invoca únicamente cuando RR.HH. fuerza el estado de un día ya
     * procesado (AsistenciaDecisionService::editarDia() con `estado`
     * forzado). Limpia toda incidencia automática que ya no corresponde al
     * estado forzado — incluida "falta": CalcularBoletaColaborador cuenta
     * `dias_falta` leyendo directamente `AsistenciaResultadoDiario.estado`
     * (nunca el estado de la incidencia), así que en cuanto se fuerza el
     * día a cualquier otro estado, el descuento por falta YA no se aplica —
     * dejar la incidencia pendiente después de eso es solo una formalidad
     * sin efecto económico, y forzar el estado ya exige motivo obligatorio
     * y queda auditado (mismo nivel de trazabilidad que Aprobar/Rechazar la
     * incidencia a mano).
     *
     * Queda afuera "día sin clasificar": a diferencia de los demás, tiene su
     * propio flujo obligatorio (resolverDiaSinClasificar()) que además
     * escribe la planificación real en colaborador_calendario_dias — forzar
     * el estado acá no replica ese efecto, así que no debe darlo por
     * resuelto.
     */
    public function limpiarIncidenciasDePresenteForzado(AsistenciaResultadoDiario $resultado): void
    {
        $tiposLimpiablesPorForzado = [
            AsistenciaIncidencia::TIPO_FALTA,
            AsistenciaIncidencia::TIPO_MARCACION_INCOMPLETA,
            AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO,
            AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS,
        ];

        $obsoletas = $resultado->incidencias()
            ->whereIn('tipo', $tiposLimpiablesPorForzado)
            ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)
            ->get();

        foreach ($obsoletas as $obsoleta) {
            $this->auditoria->registrar(
                $resultado->empresa_id, null, 'incidencia_auto_eliminada', $obsoleta,
                'RR.HH. forzó el estado del día y la incidencia automática ya no aplica.',
                $obsoleta->toArray(), null,
            );
            $obsoleta->delete();
        }
    }

    private function sincronizarIncidencia(AsistenciaResultadoDiario $resultado, string $estado): void
    {
        $tiposAutomaticos = [
            AsistenciaIncidencia::TIPO_FALTA,
            AsistenciaIncidencia::TIPO_MARCACION_INCOMPLETA,
            AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO,
            AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS,
            AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR,
        ];

        $obsoletas = $resultado->incidencias()
            ->whereIn('tipo', array_diff($tiposAutomaticos, [$estado]))
            ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)
            ->get();
        foreach ($obsoletas as $obsoleta) {
            $this->auditoria->registrar(
                $resultado->empresa_id, null, 'incidencia_auto_eliminada', $obsoleta,
                'La jornada se reprocesó y la condición que originó esta incidencia automática ya no aplica.',
                $obsoleta->toArray(), null,
            );
            $obsoleta->delete();
        }

        if (! in_array($estado, $tiposAutomaticos, true)) {
            return;
        }

        $existente = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $resultado->id)->where('tipo', $estado)->first();
        if ($existente && $existente->estado !== AsistenciaIncidencia::ESTADO_PENDIENTE) {
            return;
        }

        $descripciones = [
            AsistenciaIncidencia::TIPO_FALTA => 'No se encontraron marcaciones para una jornada laborable.',
            AsistenciaIncidencia::TIPO_MARCACION_INCOMPLETA => 'Solo se encontró una marcación para la jornada.',
            AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO => 'El horario marcado no coincide con el programado, pero cumple la duración de la jornada.',
            AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS => 'La salida se registró antes de lo programado — quedan horas de la jornada sin trabajar.',
            AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR => 'Horario rotativo sin planificación para esta fecha — falta que RR.HH. clasifique el día como descanso, laborable o permiso.',
        ];

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultado->id, 'tipo' => $estado],
            [
                'empresa_id' => $resultado->empresa_id,
                'colaborador_id' => $resultado->colaborador_id,
                'fecha' => $resultado->fecha,
                'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
                'descripcion' => $descripciones[$estado],
            ]
        );
    }

    /**
     * Fase 3.1 — mismo patrón de "obsoleta se limpia con auditoría / ya
     * resuelta nunca se toca" que sincronizarIncidencia(), pero para un
     * evento que puede coexistir con cualquier $estado (no es 1 estado → 1
     * tipo, ver docblock de la constante). Reprocesar un día que YA NO
     * tiene trabajo sobre su descanso (p. ej. se corrigió una marcación
     * mal importada) limpia la incidencia PENDIENTE automáticamente; una
     * ya resuelta/rechazada por RR.HH. queda intacta.
     */
    private function sincronizarTrabajoEnDescanso(AsistenciaResultadoDiario $resultado, bool $trabajoEnDescanso): void
    {
        $existente = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $resultado->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO)
            ->first();

        if (! $trabajoEnDescanso) {
            if ($existente && $existente->estado === AsistenciaIncidencia::ESTADO_PENDIENTE) {
                $this->auditoria->registrar(
                    $resultado->empresa_id, null, 'incidencia_auto_eliminada', $existente,
                    'La jornada se reprocesó y ya no hay trabajo registrado sobre el día de descanso planificado.',
                    $existente->toArray(), null,
                );
                $existente->delete();
            }

            return;
        }

        if ($existente && $existente->estado !== AsistenciaIncidencia::ESTADO_PENDIENTE) {
            return;
        }

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultado->id, 'tipo' => AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO],
            [
                'empresa_id' => $resultado->empresa_id,
                'colaborador_id' => $resultado->colaborador_id,
                'fecha' => $resultado->fecha,
                'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
                'descripcion' => sprintf(
                    'El colaborador registró marcaciones (%s–%s, %d min trabajados) en un día planificado como descanso. Horas extra 100%% pendiente de decisión.',
                    $resultado->entrada_at?->format('H:i') ?? '—',
                    $resultado->salida_at?->format('H:i') ?? '—',
                    $resultado->minutos_trabajados,
                ),
            ]
        );
    }

    private function sincronizarHorasExtra(AsistenciaResultadoDiario $resultado): void
    {
        $tramos = [
            '25' => $resultado->minutos_extra_25,
            '35' => $resultado->minutos_extra_35,
            '100' => $resultado->minutos_extra_100,
        ];
        foreach ($tramos as $tasa => $minutos) {
            $esFeriadoTrabajado = $resultado->tipo_dia === 'feriado' && (string) $tasa === '100';
            $registro = AsistenciaHoraExtra::query()->firstOrNew([
                'resultado_diario_id' => $resultado->id,
                'tasa' => $tasa,
            ]);
            if ($minutos <= 0) {
                if ($registro->exists && $registro->estado === 'pendiente') $registro->delete();
                continue;
            }
            if ($registro->exists && $registro->estado !== 'pendiente') continue;
            $registro->fill([
                'empresa_id' => $resultado->empresa_id,
                'colaborador_id' => $resultado->colaborador_id,
                'fecha' => $resultado->fecha,
                'minutos_observados' => $minutos,
                'minutos_solicitados' => $minutos,
                // Un feriado legal trabajado se remunera automáticamente
                // con factor 2 adicional: el primer jornal ya está incluido
                // en el sueldo mensual. Los descansos ordinarios mantienen
                // su flujo explícito de pago o descanso sustitutorio.
                'minutos_aprobados' => $esFeriadoTrabajado ? $minutos : null,
                'estado' => $esFeriadoTrabajado
                    ? AsistenciaHoraExtra::ESTADO_APROBADO
                    : AsistenciaHoraExtra::ESTADO_PENDIENTE,
                'motivo' => $esFeriadoTrabajado
                    ? 'Aprobación automática por trabajo en feriado legal.'
                    : null,
                'resuelto_por' => null,
                'resuelto_at' => $esFeriadoTrabajado ? now() : null,
            ])->save();
        }
    }
}
