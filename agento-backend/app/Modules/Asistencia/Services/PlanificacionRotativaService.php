<?php

namespace App\Modules\Asistencia\Services;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Horarios Rotativos Fase 2 — planificación semanal + validación de
 * descansos. Conecta por fin `dias_descanso_rotativo_por_semana`
 * (colaborador_horario_asignaciones — capturado desde la Fase 0 pero nunca
 * antes consumido por nada) contra los días REALMENTE planificados en
 * `colaborador_calendario_dias` — la misma tabla que ya usan
 * EditarCalendarioModal/CalendarioMensualGenerator/
 * AsistenciaDecisionService::resolverDiaSinClasificar(), ninguna tabla
 * nueva.
 *
 * Puramente informativo: esta clase NUNCA bloquea el envío a Nómina (eso lo
 * sigue haciendo, únicamente, la incidencia TIPO_DIA_SIN_CLASIFICAR de la
 * Fase 1 vía AsistenciaPeriodoService::cambiarEstado('enviar_nomina')) ni
 * infiere un tipo de día por sí sola — un día sin fila en
 * colaborador_calendario_dias se reporta como "sin planificar", nunca se
 * asume descanso ni laborable.
 */
class PlanificacionRotativaService
{
    public function __construct(
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    /**
     * @return array{semana: array{desde: string, hasta: string}, colaboradores: array<int, array<string, mixed>>}
     */
    public function consultarSemana(
        Empresa $empresa,
        string $fechaSemana,
        ?string $busqueda,
        bool $soloRotativos,
        ?int $sedeId,
    ): array {
        [$inicioSemana, $finSemana] = $this->limitesSemana($fechaSemana);

        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            // Un trabajador de confianza nunca tiene calendario ni horario
            // exigible (Fase 1 — ProcesarAsistenciaDiaria::procesarNeutralConfianza,
            // StoreColaboradorRequest) — sin este filtro, con "solo
            // rotativos" desactivado, aparecería siempre como "incompleto"
            // por no tener ninguna fila de calendario, sin que eso sea real.
            ->where('es_trabajador_confianza', false)
            ->when($soloRotativos, fn ($query) => $query->whereHas(
                'asignacionesHorario',
                fn ($query) => $query->whereNull('vigencia_hasta')
                    ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo')),
            ))
            ->when($sedeId, fn ($query) => $query->where('sede_id', $sedeId))
            ->when($busqueda, fn ($query) => $query->where(function ($query) use ($busqueda) {
                $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('legajo', 'like', "%{$busqueda}%");
            }))
            // Todo en un solo roundtrip por relación (N+1 safe) — nunca una
            // consulta por colaborador dentro del map() de abajo.
            ->with([
                'horario:id,nombre,tipo_turno',
                'asignacionesHorario' => fn ($query) => $query->whereNull('vigencia_hasta')->with('horario:id,nombre,tipo_turno'),
                'calendario' => fn ($query) => $query->whereBetween('fecha', [$inicioSemana->toDateString(), $finSemana->toDateString()]),
                // Fase 3.1 — ya no se filtra por minutos_trabajados>0: se
                // necesitan TODOS los resultados de la semana para poder
                // distinguir "planificado" de "efectivamente gozado" (ver
                // resumenColaborador()), no solo los días trabajados.
                'resultadosAsistencia' => fn ($query) => $query
                    ->whereBetween('fecha', [$inicioSemana->toDateString(), $finSemana->toDateString()])
                    ->with(['incidencias' => fn ($query) => $query
                        ->where('tipo', AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO)
                        ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)]),
            ])
            ->orderBy('apellidos')->orderBy('nombres')
            ->get();

        return [
            'semana' => ['desde' => $inicioSemana->toDateString(), 'hasta' => $finSemana->toDateString()],
            'colaboradores' => $colaboradores
                ->map(fn (Colaborador $colaborador) => $this->resumenColaborador($colaborador, $inicioSemana, $finSemana))
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resumenColaborador(Colaborador $colaborador, Carbon $inicioSemana, Carbon $finSemana): array
    {
        $asignacionVigente = $colaborador->asignacionesHorario->first();
        $requeridos = $asignacionVigente?->dias_descanso_rotativo_por_semana;
        $porFecha = $colaborador->calendario->keyBy(fn (ColaboradorCalendarioDia $dia) => $dia->fecha->toDateString());
        $resultadosPorFecha = $colaborador->resultadosAsistencia->keyBy(fn (AsistenciaResultadoDiario $resultado) => $resultado->fecha->toDateString());

        // Un día antes del ingreso o después del cese nunca cuenta como "sin
        // planificar" — todavía no es (o ya dejó de ser) responsabilidad de
        // esta planificación, igual que CalendarioMensualGenerator acota su
        // generación a fecha_ingreso.
        $inicioVigente = $colaborador->fecha_ingreso?->greaterThan($inicioSemana) ? $colaborador->fecha_ingreso : $inicioSemana;
        $finVigente = $colaborador->fecha_cese?->lessThan($finSemana) ? $colaborador->fecha_cese : $finSemana;

        $dias = [];
        $planificados = 0;
        $gozados = 0;
        $laborales = 0;
        $feriados = 0;
        $sinPlanificar = 0;

        for ($fecha = $inicioSemana->copy(); $fecha->lte($finSemana); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            $fueraDePeriodo = $fecha->lt($inicioVigente) || $fecha->gt($finVigente);
            $tipo = $fueraDePeriodo ? null : $porFecha->get($fechaTexto)?->tipo;
            $resultado = $resultadosPorFecha->get($fechaTexto);
            // Fase 3.1 — "planificado" (calendario dice descanso) y
            // "gozado" (además, nadie trabajó ese día) son cosas distintas
            // a propósito: un descanso planificado que terminó trabajado
            // sigue contando como planificado (la planificación no se
            // reescribe, Sección 7 del encargo), pero NO como gozado.
            $trabajoEnDescanso = $tipo === 'descanso' && (int) $resultado?->minutos_trabajados > 0;

            if (! $fueraDePeriodo) {
                match ($tipo) {
                    'descanso' => $planificados++,
                    'laborable_presencial', 'home_office' => $laborales++,
                    'feriado' => $feriados++,
                    default => $sinPlanificar++,
                };
                if ($tipo === 'descanso' && ! $trabajoEnDescanso) {
                    $gozados++;
                }
            }

            $dias[] = [
                'fecha' => $fechaTexto,
                'tipo' => $fueraDePeriodo ? 'fuera_de_periodo' : $tipo,
                // Descanso planificado pero con marcaciones reales ese
                // día — solo informativo, no cambia ninguna regla de pago.
                'trabajo_en_descanso' => $trabajoEnDescanso,
                // Presente solo si la incidencia sigue pendiente de
                // decisión de RR.HH. — permite a la UI enlazar directo a
                // su resolución (Sección 27 del encargo).
                'incidencia_trabajo_en_descanso_id' => $trabajoEnDescanso
                    ? $resultado->incidencias->first()?->id
                    : null,
            ];
        }

        return [
            'colaborador_id' => $colaborador->id,
            'nombre_completo' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'legajo' => $colaborador->legajo,
            'horario' => $asignacionVigente?->horario?->nombre ?? $colaborador->horario?->nombre,
            'es_rotativo' => ($asignacionVigente?->horario?->tipo_turno ?? $colaborador->horario?->tipo_turno) === 'rotativo',
            'dias_descanso_requeridos' => $requeridos,
            'dias_descanso_planificados' => $planificados,
            // Fase 3.1 — Sección 28/31: métrica NUEVA que se agrega sin
            // reemplazar "planificados". Un descanso sustitutorio hace que
            // "planificados" pueda superar a "requeridos" sin que eso sea
            // un descuadre real — "gozados" es la cifra que sí refleja
            // cumplimiento efectivo. Deliberadamente NO se intenta inferir
            // aquí qué día sustituyó a cuál (viviría solo como texto libre
            // en motivo_resolucion) — mostrar ambas cifras es la vía segura
            // que no inventa una relación que el modelo no garantiza.
            'dias_descanso_gozados' => $gozados,
            'dias_laborales_planificados' => $laborales,
            'dias_feriados' => $feriados,
            'dias_sin_planificar' => $sinPlanificar,
            'estado' => $this->estadoSemana($requeridos, $planificados, $sinPlanificar),
            'dias' => $dias,
        ];
    }

    /**
     * "incompleto" (hay días sin ninguna fila) siempre gana, aunque el
     * conteo de descansos ya cuadre por casualidad — un día sin planificar
     * nunca debe leerse como "todo en orden". "sin_requisito_declarado"
     * es distinto de un descuadre real: el horario nunca declaró cuántos
     * descansos semanales le tocan a este colaborador, así que no hay nada
     * contra qué comparar.
     */
    private function estadoSemana(?int $requeridos, int $planificados, int $sinPlanificar): string
    {
        if ($sinPlanificar > 0) {
            return 'incompleto';
        }
        if ($requeridos === null) {
            return 'sin_requisito_declarado';
        }

        return $planificados === $requeridos ? 'ok' : 'descuadre';
    }

    public function planificarDia(Empresa $empresa, Colaborador $colaborador, string $fecha, ?string $tipo, User $usuario): void
    {
        abort_unless($colaborador->empresa_id === $empresa->id, 404);
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);

        $existente = $colaborador->calendario()->where('fecha', $fecha)->first();

        // Fase 3.1 — Sección 22/Caso 6: si esta fecha YA registra trabajo
        // real sobre un descanso planificado, este endpoint genérico no
        // puede "borrarlo" cambiando el tipo (o quitando la planificación)
        // como si nada hubiera pasado — eso destruiría en silencio la
        // evidencia de que trabajó su descanso. Se exige resolver primero
        // la incidencia trabajo_en_descanso por su flujo especializado
        // (AsistenciaDecisionService::resolverTrabajoEnDescanso()), que sí
        // sabe cómo tratar la HE 100% asociada.
        if ($existente?->tipo === 'descanso' && $tipo !== 'descanso') {
            $resultado = AsistenciaResultadoDiario::query()
                ->where('colaborador_id', $colaborador->id)->whereDate('fecha', $fecha)->first();
            if ((int) $resultado?->minutos_trabajados > 0) {
                throw ValidationException::withMessages([
                    'tipo' => ['Esta fecha registra trabajo sobre un día de descanso. Resuelve primero la incidencia "Trabajo en descanso".'],
                ]);
            }
        }

        DB::transaction(function () use ($empresa, $colaborador, $fecha, $tipo, $usuario, $existente) {
            if ($tipo === null && ! $existente) {
                return;
            }

            $antes = $existente?->toArray();
            $fila = $tipo === null
                ? tap($existente)->delete()
                : $colaborador->calendario()->updateOrCreate(['fecha' => $fecha], ['tipo' => $tipo]);

            $this->auditoria->registrar(
                $empresa->id, $usuario->id, 'planificacion_dia', $fila,
                "Planificación del {$fecha}".($tipo ? " marcada como '{$tipo}'." : ' eliminada.'),
                $antes, $tipo ? $fila->fresh()->toArray() : null,
            );

            $this->reprocesarSiYaExiste($colaborador, $fecha);
        });
    }

    /**
     * Reutiliza planificarDia() por cada combinación colaborador × fecha —
     * misma validación de período, misma auditoría por día, mismo
     * reprocesamiento condicionado — en vez de reimplementar la escritura.
     * Una transacción externa envuelve todas las combinaciones para que la
     * asignación masiva sea atómica.
     *
     * @param  array<int, int>  $colaboradorIds
     * @param  array<int, string>  $fechas
     */
    public function planificarMasivo(Empresa $empresa, array $colaboradorIds, array $fechas, string $tipo, User $usuario): int
    {
        $colaboradores = Colaborador::query()->where('empresa_id', $empresa->id)->whereIn('id', $colaboradorIds)->get();
        abort_if($colaboradores->count() !== count($colaboradorIds), 404, 'Uno o más colaboradores no pertenecen a la empresa activa.');

        return DB::transaction(function () use ($empresa, $colaboradores, $fechas, $tipo, $usuario) {
            $procesadas = 0;
            foreach ($colaboradores as $colaborador) {
                foreach ($fechas as $fecha) {
                    $this->planificarDia($empresa, $colaborador, $fecha, $tipo, $usuario);
                    $procesadas++;
                }
            }

            return $procesadas;
        });
    }

    /**
     * Solo reprocesa si el día YA tenía un resultado calculado (p. ej. se
     * corrige un rol de una fecha reciente ya pasada, o el día había
     * quedado con la incidencia TIPO_DIA_SIN_CLASIFICAR). Una fecha
     * puramente futura, sin resultado todavía, nunca se reprocesa acá —
     * ProcesarAsistenciaDiaria::procesar() no distingue pasado/futuro por sí
     * mismo y, sin marcaciones, generaría una "falta" prematura para un día
     * que todavía no ocurre.
     */
    private function reprocesarSiYaExiste(Colaborador $colaborador, string $fecha): void
    {
        $existe = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha', $fecha)
            ->exists();

        if ($existe) {
            $this->procesador->procesar($colaborador, Carbon::parse($fecha));
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function limitesSemana(string $fecha): array
    {
        $inicio = Carbon::parse($fecha)->startOfWeek(Carbon::MONDAY);

        return [$inicio, $inicio->copy()->endOfWeek(Carbon::SUNDAY)];
    }
}
