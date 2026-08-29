<?php

namespace App\Modules\Personas\Support;

use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El calendario inicial de un colaborador (Paso 2 del alta) solo cubre su
 * mes de ingreso — a partir del segundo mes no existe ninguna fila en
 * colaborador_calendario_dias para él. Esta clase genera el mes faltante
 * bajo demanda: el tipo de cada día se hereda del mes más reciente que sí
 * tenga datos, comparando el mismo día de la semana **y la misma paridad
 * de semana ISO** (par/impar) — así se replica correctamente un patrón
 * quincenal alternado (p. ej. "miércoles de semana par, jueves de semana
 * impar"), no solo un patrón semanal fijo. Los feriados propios del mes
 * objetivo se vuelven a aplicar automáticamente. El resultado se persiste
 * como filas reales — queda editable igual que cualquier otro mes.
 */
class CalendarioMensualGenerator
{
    /**
     * @return Collection<int, ColaboradorCalendarioDia>
     */
    public static function paraMes(Colaborador $colaborador, int $anio, int $mes): Collection
    {
        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->copy()->endOfMonth();

        $existentes = self::consultarMes($colaborador, $inicioMes, $finMes);

        // Un horario rotativo NUNCA genera su calendario solo -- ni
        // heredando el patrón de otro mes, ni asumiendo un tipo por
        // defecto según el día de semana. El día de descanso rotativo
        // exige declaración explícita (Sección: rotativos, cero
        // inferencia) — ver completarSinPersistir().
        if (self::tieneHorarioRotativo($colaborador, $inicioMes)) {
            return self::completarSinPersistir($colaborador, $existentes, $inicioMes, $finMes);
        }

        $fechaIngreso = $colaborador->fecha_ingreso->copy()->startOfDay();
        $desde = $fechaIngreso->gt($inicioMes) ? $fechaIngreso : $inicioMes->copy();

        if ($desde->gt($finMes)) {
            return $existentes;
        }

        // Fase 4C — antes se retornaba temprano si el mes ya tenía
        // CUALQUIER fila, dejando huecos sin completar (ej. tras invalidar
        // automáticas por un cambio de horario, o un mes con una sola
        // fecha declarada a mano). Ahora las filas existentes se preservan
        // TAL CUAL — nunca se sobrescriben — y solo se generan las fechas
        // realmente faltantes dentro del mismo mes.
        $fechasExistentes = $existentes->map(fn (ColaboradorCalendarioDia $dia) => $dia->fecha->toDateString())->flip();
        $patron = self::patronDesdeMesesAnteriores($colaborador, $inicioMes);

        $filas = [];
        for ($fecha = $desde->copy(); $fecha->lte($finMes); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            if ($fechasExistentes->has($fechaTexto)) {
                continue;
            }

            $esFeriado = FeriadosPeru::esFeriado($fechaTexto);
            $filas[] = [
                'colaborador_id' => $colaborador->id,
                'fecha' => $fechaTexto,
                // Sin patrón heredado (recién ingresado, o el horario
                // cambió y ya no hay historial vigente para heredar — ver
                // patronDesdeMesesAnteriores), se resuelve directo desde el
                // horario ACTUALMENTE vigente para esa fecha, nunca un
                // default ciego a 'laborable_presencial'.
                'tipo' => $esFeriado ? 'feriado' : ($patron[self::claveSemana($fecha)] ?? self::tipoDesdeHorarioVigente($colaborador, $fecha)),
                // El origen describe CÓMO se creó la fila, no qué tipo
                // contiene — acá siempre es automático porque esta fecha
                // no tenía ninguna fila propia, nunca se pisa una decisión
                // humana existente.
                'origen' => $esFeriado ? ColaboradorCalendarioDia::ORIGEN_FERIADO_AUTOMATICO : ColaboradorCalendarioDia::ORIGEN_HORARIO_AUTOMATICO,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($filas === []) {
            return $existentes;
        }

        ColaboradorCalendarioDia::query()->insert($filas);

        return self::consultarMes($colaborador, $inicioMes, $finMes);
    }

    /**
     * @return Collection<int, ColaboradorCalendarioDia>
     */
    private static function consultarMes(Colaborador $colaborador, Carbon $inicioMes, Carbon $finMes): Collection
    {
        return ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()])
            ->orderBy('fecha')
            ->get();
    }

    /**
     * Busca hacia atrás, mes por mes si hace falta, hasta juntar un tipo
     * para cada una de las 14 combinaciones posibles (7 días × paridad de
     * semana par/impar). Se detiene apenas las completa o se queda sin
     * historial (colaborador recién ingresado, sin mes previo).
     *
     * Fase 4C — el histórico nunca se busca antes de `vigencia_desde` de
     * la asignación de horario ACTUALMENTE vigente: si el colaborador
     * cambió de horario, un patrón heredado de ANTES del cambio
     * pertenecía al horario viejo y ya no aplica (sería revivir el mismo
     * hueco que Fase 4C vino a cerrar). Sin asignación vigente (no
     * debería pasar en la práctica), no acota nada.
     *
     * @return array<string, string> clave "paridad-diaISO" => tipo
     */
    private static function patronDesdeMesesAnteriores(Colaborador $colaborador, Carbon $inicioMes): array
    {
        $vigenciaActual = ColaboradorHorarioAsignacion::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $inicioMes->toDateString())
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $inicioMes->toDateString()))
            ->orderByDesc('vigencia_desde')
            ->value('vigencia_desde');

        $anteriores = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('fecha', '<', $inicioMes->toDateString())
            ->when($vigenciaActual, fn ($query) => $query->where('fecha', '>=', $vigenciaActual))
            ->where('tipo', '!=', 'feriado')
            ->orderByDesc('fecha')
            ->get();

        $patron = [];
        foreach ($anteriores as $dia) {
            $clave = self::claveSemana($dia->fecha);
            $patron[$clave] ??= $dia->tipo;
            if (count($patron) === 14) {
                break;
            }
        }

        return $patron;
    }

    /**
     * Fallback cuando no hay patrón histórico que heredar (recién
     * ingresado, o recién cambió de horario) — resuelve directo desde el
     * HorarioDia del horario vigente en esa fecha exacta, mismo criterio
     * de resolución que ya usa ResolverJornadaDiaria (Asistencia) para
     * decidir si un día es laborable o descanso.
     */
    private static function tipoDesdeHorarioVigente(Colaborador $colaborador, Carbon $fecha): string
    {
        $asignacion = ColaboradorHorarioAsignacion::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fecha->toDateString())
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $fecha->toDateString()))
            ->with('horario.dias')
            ->orderByDesc('vigencia_desde')
            ->first();

        $horarioDia = $asignacion?->horario?->dias->firstWhere('dia_semana', $fecha->dayOfWeekIso - 1);

        return $horarioDia?->estado === 'descanso' ? 'descanso' : 'laborable_presencial';
    }

    /**
     * "paridad de semana ISO"-"día ISO" — p. ej. "0-3" es un miércoles de
     * semana par, "1-3" un miércoles de semana impar. La semana ISO es
     * continua a lo largo del año, así que la alternancia par/impar se
     * mantiene correctamente de un mes a otro (con el único límite normal
     * de que puede reiniciar en el cambio de año).
     */
    private static function claveSemana(Carbon $fecha): string
    {
        return ($fecha->isoWeek() % 2).'-'.$fecha->dayOfWeekIso;
    }

    /**
     * Completa el mes con instancias SIN GUARDAR para los días que todavía
     * no tienen un tipo declarado a mano — solo para que la grilla de
     * edición tenga los días del mes completos para hacer click. Nunca se
     * insertan por sí solas: si nadie las guarda explícitamente
     * (POST/PUT del calendario), no quedan en la base, y por lo tanto
     * ResolverJornadaDiaria las resuelve como "sin_rol_definido" — Rotativo
     * Fase 1: ProcesarAsistenciaDiaria ya no bloquea el procesamiento por
     * esto, persiste el día con una incidencia TIPO_DIA_SIN_CLASIFICAR
     * pendiente en vez de asumir cualquier valor.
     */
    private static function completarSinPersistir(
        Colaborador $colaborador,
        Collection $existentes,
        Carbon $inicioMes,
        Carbon $finMes,
    ): Collection {
        $fechaIngreso = $colaborador->fecha_ingreso->copy()->startOfDay();
        $desde = $fechaIngreso->gt($inicioMes) ? $fechaIngreso : $inicioMes->copy();

        if ($desde->gt($finMes)) {
            return $existentes;
        }

        $porFecha = $existentes->keyBy(fn (ColaboradorCalendarioDia $dia) => $dia->fecha->toDateString());
        $completo = collect();

        for ($fecha = $desde->copy(); $fecha->lte($finMes); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            if ($porFecha->has($fechaTexto)) {
                $completo->push($porFecha->get($fechaTexto));

                continue;
            }

            $completo->push(new ColaboradorCalendarioDia([
                'colaborador_id' => $colaborador->id,
                'fecha' => $fechaTexto,
                'tipo' => FeriadosPeru::esFeriado($fechaTexto) ? 'feriado' : 'laborable_presencial',
            ]));
        }

        return $completo->sortBy('fecha')->values();
    }

    private static function tieneHorarioRotativo(Colaborador $colaborador, Carbon $fecha): bool
    {
        return ColaboradorHorarioAsignacion::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('vigencia_desde', '<=', $fecha->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('vigencia_hasta')
                ->orWhereDate('vigencia_hasta', '>=', $fecha->toDateString()))
            ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo'))
            ->exists();
    }
}
