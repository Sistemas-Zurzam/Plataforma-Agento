<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Application\AsegurarCoberturaAsistenciaPeriodo;
use App\Modules\Asistencia\Application\AsignarDescansoFlexibleSemanal;
use App\Modules\Asistencia\Jobs\AsegurarCoberturaAsistenciaPeriodoJob;
use App\Modules\Asistencia\Models\AsistenciaPeriodo;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsistenciaPeriodoService
{
    /** Traduce el `tipo` automático de AsistenciaIncidencia a una etiqueta legible para RR.HH. */
    private const ETIQUETAS_INCIDENCIA = [
        AsistenciaIncidencia::TIPO_FALTA => 'faltas',
        AsistenciaIncidencia::TIPO_MARCACION_INCOMPLETA => 'marcaciones incompletas',
        AsistenciaIncidencia::TIPO_HORARIO_DESPLAZADO => 'horarios desplazados',
        AsistenciaIncidencia::TIPO_HORAS_INCOMPLETAS => 'horas incompletas',
        AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR => 'días sin clasificar',
        AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO => 'trabajos en descanso pendientes de decisión',
        AsistenciaIncidencia::TIPO_SIN_DESCANSO_SEMANAL => 'semanas sin descanso',
        AsistenciaIncidencia::TIPO_DESCANSO_FLEXIBLE_INCOMPLETO => 'descansos flexibles incompletos',
        AsistenciaIncidencia::TIPO_SEMANA_ROTATIVA_OMITIDA => 'semanas rotativas omitidas',
    ];

    public function __construct(
        private readonly AsistenciaAuditoriaService $auditoria,
        private readonly AsegurarCoberturaAsistenciaPeriodo $cobertura,
        private readonly AsignarDescansoFlexibleSemanal $descansoFlexible,
    ) {}

    /**
     * Fase 4A.1 — orquesta el cierre: valida fecha, dispara/consulta la
     * cobertura en curso, y solo si todo está resuelto delega en
     * cambiarEstado() (que vuelve a validar todo de forma independiente —
     * ver su docblock, es la capa que realmente protege la invariante a
     * nivel de servicio, no solo esta orquestación de UI).
     *
     * @return array<string, mixed>|null null = todo listo, seguir con cambiarEstado('cerrar').
     *                                    array = cobertura en curso (202), el período sigue abierto.
     *
     * @throws ValidationException fecha futura, cobertura en error, o pendientes sin resolver — cierre bloqueado.
     */
    public function prepararCierre(Empresa $empresa, AsistenciaPeriodo $periodo, int $usuarioId): ?array
    {
        abort_unless($periodo->empresa_id === $empresa->id, 404);

        if ($periodo->estado !== 'abierto') {
            // Deja que cambiarEstado() lo rechace con su mensaje de
            // transición inválida — no hay nada de cobertura que evaluar
            // para un período que no se puede cerrar de todas formas.
            return null;
        }

        $this->asegurarSinFechasFuturas($periodo);

        // Fase 1 (transaccional, sin cambios de fondo respecto a la versión
        // previa): decide si hace falta encolar la cobertura diaria. El
        // lockForUpdate solo protege ESTA decisión (evitar que dos clics
        // casi simultáneos encolen el job dos veces) — nunca debe envolver
        // pasos posteriores que puedan lanzar una ValidationException,
        // porque eso revertiría también cualquier escritura legítima hecha
        // mientras tanto (ver Fase 2).
        $cobertura = DB::transaction(function () use ($empresa, $periodo, $usuarioId) {
            $periodo = AsistenciaPeriodo::query()->lockForUpdate()->findOrFail($periodo->id);

            if ($periodo->cobertura_estado === 'en_proceso') {
                return ['message' => 'La cobertura de asistencia todavía se está procesando.', 'cobertura_estado' => 'en_proceso'];
            }

            // 'error' no es un callejón sin salida: no queda una marca
            // permanente que bloquee "Cerrar" para siempre. Las
            // combinaciones que fallaron nunca llegaron a persistirse, así
            // que contarFaltantes() las vuelve a encontrar y el mismo
            // mecanismo de abajo las reintenta — "reintento controlado"
            // significa que hace falta un nuevo clic en "Cerrar", nunca un
            // reintento automático silencioso. El resumen del error previo
            // ya se le mostró a RR.HH. cuando terminó el job anterior
            // (pollEstadoCobertura en el frontend), no se pierde acá.
            $reintentandoTrasError = $periodo->cobertura_estado === 'error';

            if ($this->cobertura->contarFaltantes($empresa, $periodo) > 0) {
                $periodo->update([
                    'cobertura_estado' => 'en_proceso',
                    'cobertura_iniciado_at' => now(),
                    'cobertura_finalizado_at' => null,
                    'cobertura_resultado' => null,
                ]);

                AsegurarCoberturaAsistenciaPeriodoJob::dispatch($empresa->id, $periodo->id);

                $this->auditoria->registrar($empresa->id, $usuarioId, 'cobertura_iniciada', $periodo, null, null, null);

                return [
                    'message' => $reintentandoTrasError
                        ? 'La verificación anterior terminó con errores — Agento está reintentando completar la cobertura del período.'
                        : 'Se detectaron días de asistencia aún no procesados. Agento está completando la cobertura del período antes de poder cerrarlo.',
                    'cobertura_estado' => 'en_proceso',
                ];
            }

            return null;
        });

        if ($cobertura !== null) {
            return $cobertura;
        }

        // Fase 2 — la cobertura diaria ya está confirmada completa. El
        // descanso semanal flexible automático (opt-in) corre acá, FUERA de
        // la transacción de arriba y con sus propias transacciones por
        // segmento (AsignarDescansoFlexibleSemanal::persistirSegmento()):
        // si genera una incidencia semanal, debe quedar realmente
        // persistida aunque el chequeo de pendientes de abajo rechace este
        // intento de cierre — de lo contrario la incidencia desaparecería
        // junto con el rollback, y RR.HH. nunca podría encontrarla para
        // resolverla. Reutiliza pendientesPeriodo() tal cual (ya agrupa
        // cualquier incidencia pendiente por tipo) para bloquear el cierre
        // — sin ningún gate de bloqueo nuevo.
        if ($empresa->descanso_flexible_automatico) {
            $this->descansoFlexible->procesarPeriodo($empresa, $periodo, $usuarioId);
        }

        $pendientes = $this->pendientesPeriodo($empresa, $periodo);
        if ($pendientes !== null) {
            throw ValidationException::withMessages(['pendientes' => [$this->mensajePendientes($pendientes)]]);
        }

        return null;
    }

    public function listar(Empresa $empresa, int $perPage = 25): LengthAwarePaginator
    {
        return AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)
            ->orderByDesc('fecha_inicio')->paginate($perPage);
    }

    public function crear(Empresa $empresa, array $datos, int $usuarioId): AsistenciaPeriodo
    {
        $solapado = AsistenciaPeriodo::query()->where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
            ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])->exists();
        if ($solapado) throw ValidationException::withMessages(['fecha_inicio' => ['El rango se superpone con otro período de asistencia.']]);

        $periodo = AsistenciaPeriodo::query()->create([...$datos, 'empresa_id' => $empresa->id, 'estado' => 'abierto']);
        $this->auditoria->registrar($empresa->id, $usuarioId, 'periodo_creado', $periodo, null, null, $periodo->toArray());
        return $periodo;
    }

    /**
     * Fase 4A.1 — la rama 'cerrar' vuelve a validar fecha/cobertura/
     * pendientes de forma independiente, aunque prepararCierre() ya lo
     * haya hecho: la invariante "un período no puede quedar cerrado con
     * cobertura incompleta o pendientes" debe sostenerse a este nivel,
     * no solo como comportamiento del controller — cualquier otro caller
     * futuro de cambiarEstado('cerrar') queda protegido igual.
     */
    public function cambiarEstado(Empresa $empresa, AsistenciaPeriodo $periodo, string $accion, int $usuarioId, string $motivo): AsistenciaPeriodo
    {
        abort_unless($periodo->empresa_id === $empresa->id, 404);
        return DB::transaction(function () use ($empresa, $periodo, $accion, $usuarioId, $motivo) {
            $antes = $periodo->toArray();
            if ($accion === 'cerrar' && $periodo->estado === 'abierto') {
                $this->asegurarSinFechasFuturas($periodo);
                if ($this->cobertura->contarFaltantes($empresa, $periodo) > 0) {
                    throw ValidationException::withMessages([
                        'cobertura' => ['Todavía hay fechas de asistencia sin procesar. Usa "Cerrar" de nuevo para completar la cobertura antes de continuar.'],
                    ]);
                }
                $pendientes = $this->pendientesPeriodo($empresa, $periodo);
                if ($pendientes !== null) {
                    throw ValidationException::withMessages(['pendientes' => [$this->mensajePendientes($pendientes)]]);
                }
                $periodo->update(['estado' => 'cerrado', 'cerrado_at' => now(), 'cerrado_por' => $usuarioId]);
            } elseif ($accion === 'reabrir' && $periodo->estado === 'cerrado') {
                $periodo->update([
                    'estado' => 'abierto', 'reabierto_at' => now(), 'reabierto_por' => $usuarioId,
                    'version' => $periodo->version + 1, 'snapshot_nomina' => null,
                    'enviado_nomina_at' => null, 'enviado_nomina_por' => null,
                ]);
            } elseif ($accion === 'enviar_nomina' && $periodo->estado === 'cerrado') {
                // Defensa en profundidad para períodos cerrados ANTES de la
                // Fase 4A (nunca pasaron por prepararCierre()/cambiarEstado('cerrar')
                // con estas validaciones) — de solo lectura, nunca procesa
                // nada acá: el período ya está cerrado y
                // ProcesarAsistenciaDiaria rechazaría cualquier intento de
                // tocar esas fechas. Si falta cobertura, la única salida es
                // reabrir (lo que sí permite reprocesar) y volver a cerrar
                // (eso sí corre la validación completa de arriba).
                if ($this->cobertura->contarFaltantes($empresa, $periodo) > 0) {
                    throw ValidationException::withMessages([
                        'cobertura' => ['Este período tiene fechas de asistencia sin procesar. Reábrelo y vuelve a cerrarlo para completar la cobertura.'],
                    ]);
                }
                if ($this->pendientesPeriodo($empresa, $periodo) !== null) {
                    throw ValidationException::withMessages(['estado' => ['Resuelve las incidencias, horas extra y permisos pendientes antes de enviar el período a Nómina.']]);
                }
                $snapshot = AsistenciaResultadoDiario::query()->where('empresa_id', $empresa->id)
                    ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])
                    ->with(['colaborador:id,numero_documento,legajo', 'horasExtra'])->get()->map(fn ($resultado) => [
                        'colaborador_id' => $resultado->colaborador_id,
                        'documento' => $resultado->colaborador?->numero_documento,
                        'legajo' => $resultado->colaborador?->legajo,
                        'fecha' => $resultado->fecha->toDateString(),
                        'estado' => $resultado->estado,
                        'minutos_tardanza' => $resultado->minutos_tardanza,
                        'minutos_salida_anticipada' => $resultado->minutos_salida_anticipada,
                        'minutos_extra_25' => (int) $resultado->horasExtra->firstWhere('tasa', '25')?->minutos_aprobados,
                        'minutos_extra_35' => (int) $resultado->horasExtra->firstWhere('tasa', '35')?->minutos_aprobados,
                        'minutos_extra_100' => (int) $resultado->horasExtra->firstWhere('tasa', '100')?->minutos_aprobados,
                    ])->all();
                $periodo->update(['estado' => 'enviado_nomina', 'snapshot_nomina' => $snapshot, 'enviado_nomina_at' => now(), 'enviado_nomina_por' => $usuarioId]);
            } else {
                throw ValidationException::withMessages(['estado' => ['La transición solicitada no es válida para el período.']]);
            }
            $this->auditoria->registrar($empresa->id, $usuarioId, 'periodo_'.$accion, $periodo, $motivo, $antes, $periodo->fresh()->toArray());
            return $periodo->fresh();
        });
    }

    public function asegurarRangoEditable(int $empresaId, string $desde, string $hasta): void
    {
        $protegido = AsistenciaPeriodo::query()->where('empresa_id', $empresaId)
            ->whereIn('estado', ['cerrado', 'enviado_nomina'])
            ->whereDate('fecha_inicio', '<=', $hasta)->whereDate('fecha_fin', '>=', $desde)->exists();
        if ($protegido) throw ValidationException::withMessages(['fecha_desde' => ['El rango contiene un período cerrado o enviado a Nómina.']]);
    }

    /**
     * No se cierra un período que todavía contiene fechas futuras — cerrar
     * debe significar "esto ya terminó y quedó resuelto", no "lo congelo
     * antes de tiempo y dejo de revisar el resto". contarFaltantes() ya
     * evita crear faltas futuras, pero eso solo resuelve QUÉ se procesa,
     * no si corresponde cerrar todavía.
     */
    private function asegurarSinFechasFuturas(AsistenciaPeriodo $periodo): void
    {
        if ($periodo->fecha_fin->greaterThan($this->cobertura->hoy())) {
            throw ValidationException::withMessages([
                'fecha_fin' => ["No se puede cerrar el período porque todavía contiene fechas futuras. El período finaliza el {$periodo->fecha_fin->format('d/m/Y')}."],
            ]);
        }
    }

    /**
     * Mismas 3 validaciones que ya usaba cambiarEstado('enviar_nomina')
     * antes de la Fase 4A.1 — ahora compartidas con 'cerrar' para no
     * duplicar queries ni reglas (Fase 4A.1, punto 3). Los 6 tipos
     * automáticos de AsistenciaIncidencia (falta, marcacion_incompleta,
     * horario_desplazado, horas_incompletas, dia_sin_clasificar,
     * trabajo_en_descanso) son todos filas de la misma tabla con
     * estado=pendiente — un solo groupBy alcanza para desglosarlas.
     *
     * @return array{incidencias: array<string, int>, horas_extra: int, permisos: int}|null null si no hay ningún pendiente.
     */
    private function pendientesPeriodo(Empresa $empresa, AsistenciaPeriodo $periodo): ?array
    {
        $incidenciasPorTipo = AsistenciaIncidencia::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])
            ->where('estado', AsistenciaIncidencia::ESTADO_PENDIENTE)
            ->selectRaw('tipo, count(*) as total')->groupBy('tipo')->pluck('total', 'tipo');

        $horasPendientes = AsistenciaHoraExtra::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$periodo->fecha_inicio, $periodo->fecha_fin])->where('estado', 'pendiente')->count();

        $permisosPendientes = AsistenciaPermiso::query()->where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $periodo->fecha_fin)->whereDate('fecha_fin', '>=', $periodo->fecha_inicio)
            ->where('estado', 'pendiente')->count();

        if ($incidenciasPorTipo->isEmpty() && $horasPendientes === 0 && $permisosPendientes === 0) {
            return null;
        }

        return [
            'incidencias' => $incidenciasPorTipo->all(),
            'horas_extra' => $horasPendientes,
            'permisos' => $permisosPendientes,
        ];
    }

    /** @param array{incidencias: array<string, int>, horas_extra: int, permisos: int} $pendientes */
    private function mensajePendientes(array $pendientes): string
    {
        $partes = [];
        foreach ($pendientes['incidencias'] as $tipo => $total) {
            $partes[] = "{$total} ".(self::ETIQUETAS_INCIDENCIA[$tipo] ?? $tipo);
        }
        if ($pendientes['horas_extra'] > 0) $partes[] = "{$pendientes['horas_extra']} horas extra pendientes";
        if ($pendientes['permisos'] > 0) $partes[] = "{$pendientes['permisos']} permisos pendientes";

        return 'No se puede cerrar el período. Existen asuntos de asistencia pendientes: '.implode(', ', $partes).'. Resuélvelos antes de cerrar.';
    }
}
