<?php

namespace App\Modules\Asistencia\Application;

use App\Modules\Asistencia\Domain\DescansoFlexibleResolver;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Services\AsistenciaAuditoriaService;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Descanso semanal flexible automático — Sección 3 del plan. Clasifica días
 * de colaboradores rotativos SOLO dentro del segmento (la porción de una
 * semana) que cae en el periodo que se está cerrando. Nunca espera a que
 * termine la semana para cerrar un periodo: si la semana cruza dos
 * periodos, cada uno clasifica únicamente su propia porción, y
 * EvaluarIntegridadDescansoSemanal se encarga de auditar la semana completa
 * cuando el segmento que incluye el domingo ya se persistió.
 *
 * Importante: no clasifica solo los días "candidatos" (0 marcaciones) —
 * TODO día sin_rol_definido sin permiso de la semana necesita una fila,
 * porque ProcesarAsistenciaDiaria::procesar() deja cualquier sin_rol_definido
 * como TIPO_DIA_SIN_CLASIFICAR sin importar cuántas marcaciones tenga (ver
 * su propio match). Un día trabajado (2+ marcaciones) se escribe como
 * 'laborable_presencial' para que el motor normal lo resuelva solo como
 * 'presente' — si no, seguiría bloqueando el cierre exactamente igual que
 * sin esta función, y "RR.HH. solo revisa excepciones" dejaría de ser
 * cierto para cualquier semana con al menos un día trabajado.
 */
class AsignarDescansoFlexibleSemanal
{
    public function __construct(
        private readonly ResolverJornadaDiaria $resolverJornada,
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly EvaluarIntegridadDescansoSemanal $evaluadorIntegridad,
        private readonly AsistenciaAuditoriaService $auditoria,
    ) {}

    /**
     * Punto de entrada desde el cierre de periodo (AsistenciaPeriodoService::prepararCierre()).
     * Recorre los colaboradores rotativos elegibles y, para cada semana con
     * algún día todavía sin planificar dentro del rango de $periodo,
     * calcula y persiste el segmento correspondiente.
     */
    public function procesarPeriodo(Empresa $empresa, AsistenciaPeriodo $periodo, ?int $usuarioId): void
    {
        $colaboradores = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->where('es_trabajador_confianza', false)
            ->whereDate('fecha_ingreso', '<=', $periodo->fecha_fin->toDateString())
            ->where(fn ($query) => $query->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', $periodo->fecha_inicio->toDateString()))
            ->whereHas('asignacionesHorario', fn ($query) => $query->whereNull('vigencia_hasta')
                ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo')))
            ->get();

        foreach ($colaboradores as $colaborador) {
            $this->procesarColaborador($empresa, $colaborador, $periodo, $usuarioId);
        }
    }

    private function procesarColaborador(Empresa $empresa, Colaborador $colaborador, AsistenciaPeriodo $periodo, ?int $usuarioId): void
    {
        $asignacion = ColaboradorHorarioAsignacion::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNull('vigencia_hasta')
            ->with('horario')
            ->first();
        $requeridos = $asignacion?->dias_descanso_rotativo_por_semana;

        $inicioVigente = $colaborador->fecha_ingreso->greaterThan($periodo->fecha_inicio) ? $colaborador->fecha_ingreso->copy() : $periodo->fecha_inicio->copy();
        $finVigente = $colaborador->fecha_cese?->lessThan($periodo->fecha_fin) ? $colaborador->fecha_cese->copy() : $periodo->fecha_fin->copy();
        if ($inicioVigente->gt($finVigente)) {
            return;
        }

        for ($inicioSemana = $inicioVigente->copy()->startOfWeek(Carbon::MONDAY); $inicioSemana->lte($finVigente); $inicioSemana->addWeek()) {
            $finSemana = $inicioSemana->copy()->addDays(6);
            $inicioSegmento = $inicioSemana->copy()->max($inicioVigente)->copy();
            $finSegmento = $finSemana->copy()->min($finVigente)->copy();
            if ($inicioSegmento->gt($finSegmento)) {
                continue;
            }

            if ($requeridos === null) {
                if ($this->tieneAlgunDiaSinRolDefinido($colaborador, $inicioSegmento, $finSegmento)) {
                    $this->generarSemanaOmitidaPorDatos(
                        $empresa, $colaborador, $inicioSegmento,
                        'El colaborador tiene horario rotativo pero no tiene configurado "días de descanso a la semana" — no se puede aplicar el descanso flexible automático.',
                    );
                }

                continue;
            }

            $veredictos = $this->calcularSegmento($colaborador, $inicioSemana, $inicioSegmento, $finSegmento, $requeridos);
            if ($veredictos !== []) {
                $this->persistirSegmento($empresa, $colaborador, $inicioSegmento, $finSegmento, $veredictos, $usuarioId);
            }

            if ($finSegmento->gte($finSemana)) {
                $this->evaluadorIntegridad->evaluar($empresa, $colaborador, $inicioSemana, $requeridos);
            }
        }
    }

    /**
     * Solo lectura. Devuelve fecha => 'descanso'|'falta'|'laboral' para
     * TODO día sin_rol_definido sin permiso del segmento (no solo los
     * candidatos) — nunca escribe nada. 'falta' y 'laboral' terminan
     * escribiéndose igual (laborable_presencial); se distinguen solo para
     * que la auditoría explique la diferencia entre "excedía el cupo de
     * descansos" y "tenía marcaciones reales".
     *
     * @return array<string, string>
     */
    public function calcularSegmento(Colaborador $colaborador, Carbon $inicioSemana, Carbon $inicioSegmento, Carbon $finSegmento, int $diasDescansoRequeridos): array
    {
        $yaAsignados = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha', '>=', $inicioSemana->toDateString())
            ->whereDate('fecha', '<', $inicioSegmento->toDateString())
            ->where('tipo', 'descanso')
            ->count();
        $remanente = max(0, $diasDescansoRequeridos - $yaAsignados);

        $diasSinRolSinPermiso = [];
        for ($fecha = $inicioSegmento->copy(); $fecha->lte($finSegmento); $fecha->addDay()) {
            if (! $this->esSinRolYSinPermiso($colaborador, $fecha)) {
                continue;
            }
            $diasSinRolSinPermiso[$fecha->toDateString()] = $this->contarMarcaciones($colaborador, $fecha) === 0;
        }

        $dias = array_map(
            fn (string $fecha, bool $esCandidato) => ['fecha' => $fecha, 'esCandidato' => $esCandidato],
            array_keys($diasSinRolSinPermiso),
            array_values($diasSinRolSinPermiso),
        );
        $veredictosCandidatos = DescansoFlexibleResolver::resolver($dias, $remanente);

        $veredictos = [];
        foreach ($diasSinRolSinPermiso as $fecha => $esCandidato) {
            // Los candidatos ya vienen resueltos ('descanso'/'falta') por
            // DescansoFlexibleResolver; cualquier día sin_rol_definido que
            // NO era candidato (tenía marcaciones) se etiqueta 'laboral'
            // acá mismo — nunca lo decide el resolver de dominio, que solo
            // conoce candidatos.
            $veredictos[$fecha] = $veredictosCandidatos[$fecha] ?? 'laboral';
        }

        return $veredictos;
    }

    /**
     * Evita generar SEMANA_ROTATIVA_OMITIDA cuando en realidad no había
     * nada que clasificar en este segmento (todos los días ya están
     * resueltos por feriado, planificación manual, o son de otro tipo) —
     * la falta de dias_descanso_rotativo_por_semana solo importa si de
     * verdad existe un día sin_rol_definido esperando ser clasificado.
     */
    private function tieneAlgunDiaSinRolDefinido(Colaborador $colaborador, Carbon $inicioSegmento, Carbon $finSegmento): bool
    {
        for ($fecha = $inicioSegmento->copy(); $fecha->lte($finSegmento); $fecha->addDay()) {
            if ($this->esSinRolYSinPermiso($colaborador, $fecha)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Día rotativo sin planificación (sin_rol_definido) y sin permiso
     * aprobado superpuesto — un día con permiso ya se resuelve solo como
     * 'permiso' en ProcesarAsistenciaDiaria::procesar() sin necesitar
     * ninguna fila de calendario, así que nunca entra a esta función.
     */
    private function esSinRolYSinPermiso(Colaborador $colaborador, Carbon $fecha): bool
    {
        $jornada = $this->resolverJornada->resolver($colaborador, $fecha);
        if ($jornada['tipo_dia'] !== 'sin_rol_definido') {
            return false;
        }

        $tienePermiso = AsistenciaPermiso::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->whereDate('fecha_fin', '>=', $fecha->toDateString())
            ->exists();

        return ! $tienePermiso;
    }

    private function contarMarcaciones(Colaborador $colaborador, Carbon $fecha): int
    {
        return AsistenciaMarcacion::query()
            ->where('empresa_id', $colaborador->empresa_id)
            ->whereNull('anulada_at')
            ->where(fn ($query) => $query->where('colaborador_id', $colaborador->id)
                ->orWhere('person_id', $colaborador->numero_documento))
            ->whereBetween('marcado_at', [$fecha->copy()->startOfDay(), $fecha->copy()->endOfDay()])
            ->count();
    }

    /**
     * Escribe SOLO fechas de [inicioSegmento, finSegmento], dentro de una
     * transacción única. Vuelve a verificar el candado de periodo en el
     * momento de escribir (no confía en lo que calcularSegmento() vio
     * antes) — si algo ya no es editable, no persiste nada de este
     * segmento y lo reporta como omitido en vez de escribir a medias.
     *
     * @param  array<string, string>  $veredictos
     */
    public function persistirSegmento(Empresa $empresa, Colaborador $colaborador, Carbon $inicioSegmento, Carbon $finSegmento, array $veredictos, ?int $usuarioId): void
    {
        $protegido = AsistenciaPeriodo::query()
            ->where('empresa_id', $empresa->id)
            ->whereIn('estado', ['cerrado', 'enviado_nomina'])
            ->whereDate('fecha_inicio', '<=', $finSegmento->toDateString())
            ->whereDate('fecha_fin', '>=', $inicioSegmento->toDateString())
            ->exists();
        if ($protegido) {
            $this->generarSemanaOmitidaPorDatos(
                $empresa, $colaborador, $inicioSegmento,
                'El segmento dejó de estar en un periodo abierto entre el cálculo y la escritura -- se omitió para no escribir sobre un periodo protegido.',
            );

            return;
        }

        DB::transaction(function () use ($empresa, $colaborador, $inicioSegmento, $finSegmento, $veredictos, $usuarioId) {
            $previas = ColaboradorCalendarioDia::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereDate('fecha', '>=', $inicioSegmento->toDateString())
                ->whereDate('fecha', '<=', $finSegmento->toDateString())
                ->where('origen', ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO)
                ->get();
            foreach ($previas as $previa) {
                $antes = $previa->toArray();
                $fechaPrevia = $previa->fecha->toDateString();
                $previa->delete();
                $this->auditoria->registrar(
                    $empresa->id, $usuarioId, 'descanso_flexible_invalidado', $previa,
                    "Se recalculó el descanso flexible automático del {$fechaPrevia} (el periodo seguía abierto).",
                    $antes, null,
                );
            }

            foreach ($veredictos as $fecha => $veredicto) {
                // 'descanso' se escribe tal cual; 'falta' (candidato que ya
                // no tenía cupo) y 'laboral' (tenía marcaciones reales) se
                // escriben igual como laborable_presencial -- es el único
                // tipo que ProcesarAsistenciaDiaria::resolverEstado()
                // resuelve solo como 'falta' con 0 marcaciones o 'presente'
                // con 2+ (home_office con 0 marcaciones resuelve distinto,
                // por eso nunca se usa acá).
                $tipo = $veredicto === 'descanso' ? 'descanso' : 'laborable_presencial';
                $fila = ColaboradorCalendarioDia::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'fecha' => $fecha,
                    'tipo' => $tipo,
                    'origen' => ColaboradorCalendarioDia::ORIGEN_DESCANSO_FLEXIBLE_AUTOMATICO,
                ]);
                $this->auditoria->registrar(
                    $empresa->id, $usuarioId, 'descanso_flexible_asignado', $fila,
                    "Descanso semanal flexible automático: clasificado como {$veredicto}.",
                    null, $fila->toArray(),
                );
                $this->procesador->procesar($colaborador, Carbon::parse($fecha));
            }
        });
    }

    /**
     * TIPO_SEMANA_ROTATIVA_OMITIDA -- exclusiva de esta clase (nunca de
     * EvaluarIntegridadDescansoSemanal), reservada para datos insuficientes
     * o una condición de carrera al persistir. Se ancla al resultado diario
     * ya existente del primer día del segmento (AsegurarCoberturaAsistenciaPeriodo
     * garantiza que ya existe, incluso para un día "sin_rol_definido").
     */
    private function generarSemanaOmitidaPorDatos(Empresa $empresa, Colaborador $colaborador, Carbon $fechaAncla, string $descripcion): void
    {
        $resultado = $colaborador->resultadosAsistencia()->whereDate('fecha', $fechaAncla->toDateString())->first();
        if (! $resultado) {
            return;
        }

        $existente = AsistenciaIncidencia::query()
            ->where('resultado_diario_id', $resultado->id)
            ->where('tipo', AsistenciaIncidencia::TIPO_SEMANA_ROTATIVA_OMITIDA)
            ->first();
        if ($existente && $existente->estado !== AsistenciaIncidencia::ESTADO_PENDIENTE) {
            // Ya fue revisada por una persona -- nunca se reabre sola.
            return;
        }

        AsistenciaIncidencia::query()->updateOrCreate(
            ['resultado_diario_id' => $resultado->id, 'tipo' => AsistenciaIncidencia::TIPO_SEMANA_ROTATIVA_OMITIDA],
            [
                'empresa_id' => $empresa->id,
                'colaborador_id' => $colaborador->id,
                'fecha' => $resultado->fecha,
                'estado' => AsistenciaIncidencia::ESTADO_PENDIENTE,
                'descripcion' => $descripcion,
            ]
        );
    }
}
