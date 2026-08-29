<?php

namespace App\Modules\Personas\Application;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Asistencia\Services\AsistenciaPeriodoService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Support\Carbon;

/**
 * Fase 4C — al reasignar el horario de un colaborador, decide con
 * seguridad qué pasa con su calendario futuro (colaborador_calendario_dias)
 * ya generado bajo el horario anterior. Usa el `origen` de la Fase 4B como
 * única señal: solo invalida filas ORIGEN_HORARIO_AUTOMATICO — todo lo
 * demás (feriados legales, cualquier decisión humana, e incluso lo
 * histórico sin origen conocido) se conserva siempre, nunca se borra
 * automáticamente. Selección positiva exacta (`where origen = ...`, nunca
 * `!= 'manual'`), para que un origen nuevo que se agregue después, o NULL
 * histórico, jamás caigan acá por accidente.
 *
 * No reprocesa nada (nunca invoca ProcesarAsistenciaDiaria): las fechas
 * que queden sin fila tras invalidar simplemente quedan "sin planificar" —
 * las completa después CalendarioMensualGenerator (horario fijo) o
 * Planificación/Cobertura (rotativo, vía dia_sin_clasificar), cada uno
 * bajo su propio mecanismo ya existente.
 */
class AjustarCalendarioPorCambioHorario
{
    public function __construct(
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    /**
     * Chequeo de solo lectura — nunca escribe nada. `bloqueado_por_procesado`
     * es la señal fuerte de "no tocar": si ya existe CUALQUIER resultado de
     * asistencia procesado desde la vigencia, la reasignación completa se
     * rechaza (ver ColaboradorService::actualizarHorario()) en vez de
     * intentar invalidar/reprocesar automáticamente — corrección manual
     * especializada, no automática.
     *
     * @return array{bloqueado_por_procesado: bool, automaticas: int, feriados: int, humanas: int, legacy: int, requiere_confirmacion: bool}
     */
    public function evaluarImpacto(Colaborador $colaborador, string $vigenciaDesde): array
    {
        $filas = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('fecha', '>=', $vigenciaDesde)
            ->get(['fecha', 'origen']);

        $automaticas = $filas->where('origen', ColaboradorCalendarioDia::ORIGEN_HORARIO_AUTOMATICO)->count();
        $feriados = $filas->where('origen', ColaboradorCalendarioDia::ORIGEN_FERIADO_AUTOMATICO)->count();
        $legacy = $filas->whereNull('origen')->count();
        $humanas = $filas->count() - $automaticas - $feriados - $legacy;

        $bloqueadoPorProcesado = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('fecha', '>=', $vigenciaDesde)
            ->exists();

        return [
            'bloqueado_por_procesado' => $bloqueadoPorProcesado,
            'automaticas' => $automaticas,
            'feriados' => $feriados,
            'humanas' => $humanas,
            'legacy' => $legacy,
            // Feriados nunca piden confirmación (se conservan siempre, sin
            // ambigüedad) — solo lo humano y lo desconocido, porque ahí SÍ
            // podría sorprender a RR.HH. que algo que alguien declaró a
            // mano quede como "excepción" del horario nuevo.
            'requiere_confirmacion' => $humanas > 0 || $legacy > 0,
        ];
    }

    /**
     * Defensa adicional a evaluarImpacto()->bloqueado_por_procesado: con
     * cobertura completa (Fase 4A) casi siempre coinciden, pero un período
     * cerrado ANTES de esa fase pudo quedar sin cobertura total para un
     * colaborador puntual (ej. estuvo cesado en ese rango). +2 años es el
     * mismo horizonte ya usado en ReprocesarAsistenciaRequest para acotar
     * un rango lejano sin inventar un criterio nuevo.
     *
     * @throws \Illuminate\Validation\ValidationException si el rango cae en un período cerrado/enviado a Nómina.
     */
    public function asegurarSinPeriodoProtegido(int $empresaId, string $vigenciaDesde): void
    {
        $this->periodos->asegurarRangoEditable(
            $empresaId, $vigenciaDesde, Carbon::parse($vigenciaDesde)->addYears(2)->toDateString(),
        );
    }

    /**
     * Invalida (DELETE) las filas automáticas y registra auditoría. El
     * llamador ya debe haber verificado bloqueado_por_procesado=false, el
     * período no protegido, y — si requiere_confirmacion era true — que el
     * usuario confirmó explícitamente. Pensado para correr DENTRO de la
     * misma transacción que crea la nueva ColaboradorHorarioAsignacion.
     *
     * @param  array{automaticas: int, feriados: int, humanas: int, legacy: int}  $impactoPrevio
     * @return array{automaticas_eliminadas: int, feriados_conservados: int, humanas_conservadas: int, legacy_conservadas: int}
     */
    public function invalidarAutomaticas(
        Empresa $empresa,
        Colaborador $colaborador,
        string $vigenciaDesde,
        array $impactoPrevio,
        int $usuarioId,
        ?int $horarioAnteriorId,
        int $horarioNuevoId,
    ): array {
        $eliminadas = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('fecha', '>=', $vigenciaDesde)
            ->where('origen', ColaboradorCalendarioDia::ORIGEN_HORARIO_AUTOMATICO)
            ->delete();

        $resumen = [
            'automaticas_eliminadas' => $eliminadas,
            'feriados_conservados' => $impactoPrevio['feriados'],
            'humanas_conservadas' => $impactoPrevio['humanas'],
            'legacy_conservadas' => $impactoPrevio['legacy'],
        ];

        $this->auditoria->registrar(
            $empresa->id, $usuarioId, 'colaborador_cambio_horario_calendario', $colaborador,
            "Cambio de horario con vigencia desde {$vigenciaDesde}.",
            ['horario_id' => $horarioAnteriorId],
            ['horario_id' => $horarioNuevoId, ...$resumen],
        );

        return $resumen;
    }
}
