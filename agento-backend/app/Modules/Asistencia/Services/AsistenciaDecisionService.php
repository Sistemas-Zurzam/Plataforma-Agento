<?php

namespace App\Modules\Asistencia\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaSolicitudArea;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AsistenciaDecisionService
{
    public function __construct(
        private readonly AsistenciaAuditoriaService $auditoria,
        private readonly \App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaPermisoService $permisos,
    ) {}

    public function resolverIncidencia(Empresa $empresa, AsistenciaIncidencia $incidencia, array $datos, User $usuario): AsistenciaIncidencia
    {
        $this->asegurarEmpresa($empresa, $incidencia);
        $fecha = $incidencia->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);
        return DB::transaction(function () use ($empresa, $incidencia, $datos, $usuario) {
            $antes = $incidencia->toArray();
            // Estado canónico único para el flujo nuevo: 'aprobar' -> resuelta,
            // cualquier otra acción ('rechazar', 'observar', ...) -> el
            // adjetivo correspondiente, nunca el verbo crudo de la acción
            // (bug detectado en la auditoría V3: se guardaba 'rechazar' y el
            // frontend filtraba por 'rechazada', nunca coincidían). No se
            // migran registros históricos, solo se corrige lo que se escribe
            // de ahora en adelante.
            $estado = match ($datos['accion']) {
                'aprobar' => AsistenciaIncidencia::ESTADO_RESUELTA,
                'rechazar' => AsistenciaIncidencia::ESTADO_RECHAZADA,
                default => $datos['accion'],
            };
            $incidencia->update([
                'estado' => $estado,
                'motivo_resolucion' => $datos['motivo'], 'resuelto_por' => $usuario->id, 'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'incidencia_'.$datos['accion'], $incidencia, $datos['motivo'], $antes, $incidencia->fresh()->toArray());
            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    /**
     * V3 Fase 3 — A8/A11: resuelve una incidencia (Falta u Horas Incompletas
     * casi siempre, pero funciona para cualquier tipo) creando un
     * AsistenciaPermiso REAL en vez de solo cambiar `estado='permiso'` como
     * etiqueta (hueco detectado en la auditoría). No reimplementa nada:
     * encadena 3 llamadas a métodos YA EXISTENTES —
     * AsistenciaPermisoService::crear(), resolverIncidencia() y
     * resolverPermiso() de esta misma clase — cada una con su propia
     * auditoría y su propio candado de período, en una sola transacción.
     *
     * Orden importa: primero se crea el permiso (pendiente, no reprocesa
     * nada todavía) y se usa su id para dejar rastro explícito en el motivo
     * de resolución de la incidencia (Sección 27 del encargo: no hay columna
     * de metadata para "permiso_id", así que el motivo es la evidencia
     * reconstruible). Recién después se aprueba el permiso — esa aprobación
     * reprocesa el/los día(s) y podría intentar limpiar automáticamente una
     * incidencia PENDIENTE que ya no aplica (ver ProcesarAsistenciaDiaria::
     * sincronizarIncidencia), pero para entonces la incidencia ya quedó
     * `resuelta` (no pendiente), así que ese saneamiento automático la deja
     * intacta con su auditoría humana completa.
     *
     * @param  array<string, mixed>  $datosPermiso  Mismos campos que
     *   StoreAsistenciaPermisoRequest (tipo, fecha_inicio, fecha_fin, motivo,
     *   con_goce, pagador_subsidio) — sin colaborador_id, se toma de la
     *   incidencia, nunca del request, para no confiar en un valor arbitrario.
     * @return array{incidencia: AsistenciaIncidencia, permiso: AsistenciaPermiso}
     */
    public function resolverIncidenciaConPermiso(Empresa $empresa, AsistenciaIncidencia $incidencia, array $datosPermiso, User $usuario): array
    {
        $this->asegurarEmpresa($empresa, $incidencia);

        return DB::transaction(function () use ($empresa, $incidencia, $datosPermiso, $usuario) {
            $permiso = $this->permisos->crear($empresa, [
                ...$datosPermiso,
                'colaborador_id' => $incidencia->colaborador_id,
            ], $usuario->id);

            $incidenciaResuelta = $this->resolverIncidencia($empresa, $incidencia, [
                'accion' => 'aprobar',
                'motivo' => $datosPermiso['motivo']." — resuelta registrando permiso #{$permiso->id}.",
            ], $usuario);

            $permisoAprobado = $this->resolverPermiso($empresa, $permiso, [
                'accion' => 'aprobar',
                'motivo' => "Aprobado automáticamente al resolver la incidencia #{$incidencia->id} ({$incidencia->tipo}).",
            ], $usuario);

            return ['incidencia' => $incidenciaResuelta, 'permiso' => $permisoAprobado];
        });
    }

    /**
     * V3 Rotativo Fase 1 — resuelve una incidencia TIPO_DIA_SIN_CLASIFICAR
     * con una acción de dominio explícita ('descanso' | 'laboral'), nunca
     * con el "aprobar"/"rechazar" genérico de resolverIncidencia() — no
     * hay nada que "aprobar" en un día que todavía no se sabe qué es. La
     * 3ra salida posible ("Registrar permiso") NO necesita un método nuevo:
     * resolverIncidenciaConPermiso() ya funciona para cualquier tipo de
     * incidencia (ver su propio docblock), así que el frontend la reutiliza
     * tal cual contra esta misma incidencia.
     *
     * 'descanso'/'laboral' escriben la planificación real en
     * colaborador_calendario_dias — la MISMA tabla que ya usa
     * EditarCalendarioModal, no una nueva — y reprocesan el día. El
     * resultado final (descanso limpio, o falta/presente/HD/HI si quedó
     * laboral) sale del flujo normal de ProcesarAsistenciaDiaria; nunca se
     * escribe un estado a mano acá.
     */
    public function resolverDiaSinClasificar(Empresa $empresa, AsistenciaIncidencia $incidencia, array $datos, User $usuario): AsistenciaIncidencia
    {
        $this->asegurarEmpresa($empresa, $incidencia);
        abort_unless(
            $incidencia->tipo === AsistenciaIncidencia::TIPO_DIA_SIN_CLASIFICAR, 422,
            'Esta acción solo aplica a incidencias de día sin clasificar.',
        );
        $fecha = $incidencia->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);

        $tipoCalendario = match ($datos['accion']) {
            'descanso' => 'descanso',
            'laboral' => 'laborable_presencial',
        };

        return DB::transaction(function () use ($empresa, $incidencia, $datos, $usuario, $fecha, $tipoCalendario) {
            $antes = $incidencia->toArray();

            // Fase 4B — origen 'incidencia': decisión humana de RR.HH. al
            // clasificar un día rotativo sin planificación, pisa cualquier
            // origen previo a propósito (no debería existir uno, ya que
            // dia_sin_clasificar solo ocurre cuando no hay fila).
            ColaboradorCalendarioDia::query()->updateOrCreate(
                ['colaborador_id' => $incidencia->colaborador_id, 'fecha' => $fecha],
                ['tipo' => $tipoCalendario, 'origen' => ColaboradorCalendarioDia::ORIGEN_INCIDENCIA],
            );

            $incidencia->update([
                'estado' => AsistenciaIncidencia::ESTADO_RESUELTA,
                'motivo_resolucion' => $datos['motivo'],
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar(
                $empresa->id, $usuario->id, 'dia_sin_clasificar_'.$datos['accion'], $incidencia,
                $datos['motivo'], $antes, $incidencia->fresh()->toArray(),
            );

            // Reprocesa con la planificación ya guardada — nunca se fuerza
            // el estado final a mano, sale del mismo motor de siempre.
            $this->procesador->procesar($incidencia->colaborador, $incidencia->fecha);

            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    /**
     * Fase 3.1 — resuelve una incidencia TIPO_TRABAJO_EN_DESCANSO con una de
     * 3 acciones de dominio explícitas, nunca aprobar/rechazar genérico (no
     * hay nada que "aprobar" en la incidencia misma — lo que se decide es
     * qué pasa con la HE 100% asociada y, si corresponde, la planificación).
     * No modifica la fórmula económica de HE_100 en ningún caso — solo
     * decide su estado (aprobada/rechazada) reutilizando resolverHoraExtra()
     * ya existente, nunca un UPDATE directo.
     */
    public function resolverTrabajoEnDescanso(Empresa $empresa, AsistenciaIncidencia $incidencia, array $datos, User $usuario): AsistenciaIncidencia
    {
        $this->asegurarEmpresa($empresa, $incidencia);
        abort_unless(
            $incidencia->tipo === AsistenciaIncidencia::TIPO_TRABAJO_EN_DESCANSO, 422,
            'Esta acción solo aplica a incidencias de trabajo en descanso.',
        );
        $fecha = $incidencia->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);

        $horaExtra = AsistenciaHoraExtra::query()
            ->where('resultado_diario_id', $incidencia->resultado_diario_id)
            ->where('tasa', '100')
            ->first();

        return match ($datos['accion']) {
            'pago' => $this->trabajoEnDescansoCorrespondePago($empresa, $incidencia, $horaExtra, $datos, $usuario),
            'sustitutorio' => $this->trabajoEnDescansoConSustitutorio($empresa, $incidencia, $horaExtra, $datos, $usuario),
            'corregir_planificacion' => $this->trabajoEnDescansoCorregirPlanificacion($empresa, $incidencia, $horaExtra, $datos, $usuario),
        };
    }

    private function trabajoEnDescansoCorrespondePago(Empresa $empresa, AsistenciaIncidencia $incidencia, ?AsistenciaHoraExtra $horaExtra, array $datos, User $usuario): AsistenciaIncidencia
    {
        return DB::transaction(function () use ($empresa, $incidencia, $horaExtra, $datos, $usuario) {
            $antes = $incidencia->toArray();

            if ($horaExtra && $horaExtra->estado === AsistenciaHoraExtra::ESTADO_PENDIENTE) {
                $this->resolverHoraExtra($empresa, $horaExtra, [
                    'accion' => 'aprobar',
                    'minutos_aprobados' => $horaExtra->minutos_observados,
                    'motivo' => $datos['motivo'],
                ], $usuario);
            }

            $incidencia->update([
                'estado' => AsistenciaIncidencia::ESTADO_RESUELTA,
                'motivo_resolucion' => $datos['motivo'],
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar(
                $empresa->id, $usuario->id, 'trabajo_en_descanso_pago', $incidencia,
                $datos['motivo'], $antes, $incidencia->fresh()->toArray(),
            );

            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    private function trabajoEnDescansoConSustitutorio(Empresa $empresa, AsistenciaIncidencia $incidencia, ?AsistenciaHoraExtra $horaExtra, array $datos, User $usuario): AsistenciaIncidencia
    {
        $fechaOriginal = $incidencia->fecha->toDateString();
        $fechaSustitutoria = $datos['fecha_sustitutoria'];
        $colaborador = $incidencia->colaborador;

        if ($fechaSustitutoria === $fechaOriginal) {
            throw ValidationException::withMessages(['fecha_sustitutoria' => ['El descanso sustitutorio no puede ser el mismo día trabajado.']]);
        }

        // D.Leg. 713, Art. 3°: el sustitutorio solo excluye la sobretasa del
        // 100% si cae "en la misma semana" del día trabajado. Fase 3.2.1 —
        // el Informe N° 027-2021-MTPE/14 (Dirección General de Trabajo,
        // criterio vinculante) resuelve que "semana" para el descanso NO es
        // necesariamente la semana calendario (lunes-domingo): es un CICLO
        // de 7 días, no atado a una grilla fija. La semana calendario fija
        // usada antes acá rechazaba de forma demasiado estricta un caso
        // real (descanso trabajado en domingo + sustitutorio el lunes
        // inmediato siguiente, que cae en "otra semana calendario" aunque
        // sea el día consecutivo). El ciclo se ancla al día trabajado (el
        // sustitutorio siempre es posterior a él — no tiene sentido
        // "adelantar" un descanso que compensa un día que aún no se
        // trabajaba) y se extiende 6 días hacia adelante, formando el
        // mismo ciclo de 7 días (el trabajado + 6 más) que exige el
        // criterio del MTPE.
        $fechaSustitutoriaCarbon = Carbon::parse($fechaSustitutoria);
        $limiteCiclo = Carbon::parse($fechaOriginal)->addDays(6);
        if ($fechaSustitutoriaCarbon->lt(Carbon::parse($fechaOriginal)) || $fechaSustitutoriaCarbon->gt($limiteCiclo)) {
            throw ValidationException::withMessages(['fecha_sustitutoria' => ["El descanso sustitutorio debe caer dentro del ciclo de 7 días del día trabajado (hasta el {$limiteCiclo->toDateString()})."]]);
        }

        if ($colaborador->fecha_ingreso->gt($fechaSustitutoriaCarbon) || ($colaborador->fecha_cese && $colaborador->fecha_cese->lt($fechaSustitutoriaCarbon))) {
            throw ValidationException::withMessages(['fecha_sustitutoria' => ['La fecha sustitutoria está fuera de la relación laboral vigente del colaborador.']]);
        }

        $this->periodos->asegurarRangoEditable($empresa->id, $fechaSustitutoria, $fechaSustitutoria);

        $calendarioSustituto = ColaboradorCalendarioDia::query()
            ->where('colaborador_id', $colaborador->id)->where('fecha', $fechaSustitutoria)->first();
        if ($calendarioSustituto?->tipo === 'feriado') {
            throw ValidationException::withMessages(['fecha_sustitutoria' => ['Esa fecha ya es feriado — elige otro día de la misma semana.']]);
        }

        $resultadoSustituto = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaborador->id)->whereDate('fecha', $fechaSustitutoria)->first();
        if ((int) $resultadoSustituto?->minutos_trabajados > 0) {
            throw ValidationException::withMessages(['fecha_sustitutoria' => ['Esa fecha ya registra marcaciones/trabajo — no puede convertirse automáticamente en descanso. Elige otra fecha o trátala de forma explícita primero.']]);
        }

        return DB::transaction(function () use ($empresa, $incidencia, $horaExtra, $datos, $usuario, $colaborador, $fechaSustitutoria, $resultadoSustituto) {
            $antes = $incidencia->toArray();

            // Fase 4B — origen propio ORIGEN_DESCANSO_SUSTITUTORIO, distinto
            // de 'incidencia' genérico: sirve para distinguir después un
            // descanso ordinario de uno otorgado como sustitución legal.
            ColaboradorCalendarioDia::query()->updateOrCreate(
                ['colaborador_id' => $colaborador->id, 'fecha' => $fechaSustitutoria],
                ['tipo' => 'descanso', 'origen' => ColaboradorCalendarioDia::ORIGEN_DESCANSO_SUSTITUTORIO],
            );
            // Solo reprocesa si ya existía resultado (fecha ya transcurrida
            // o ya procesada) — una fecha puramente futura solo se
            // planifica, nunca se fuerza un cálculo (mismo criterio que
            // PlanificacionRotativaService::reprocesarSiYaExiste()).
            if ($resultadoSustituto) {
                $this->procesador->procesar($colaborador, Carbon::parse($fechaSustitutoria));
            }

            // Con sustitutorio en la misma semana, el Art. 3° del D.Leg. 713
            // excluye la sobretasa del 100% — la HE queda rechazada (no
            // aprobada, no borrada) para conservar trazabilidad de que
            // existió y de por qué no se pagó.
            if ($horaExtra && $horaExtra->estado === AsistenciaHoraExtra::ESTADO_PENDIENTE) {
                $this->resolverHoraExtra($empresa, $horaExtra, [
                    'accion' => 'rechazar',
                    'motivo' => "Descanso sustitutorio otorgado el {$fechaSustitutoria} — no corresponde sobretasa 100% (D.Leg. 713, Art. 3°).",
                ], $usuario);
            }

            $motivoResolucion = "{$datos['motivo']} — Descanso sustitutorio otorgado el {$fechaSustitutoria}.";
            $incidencia->update([
                'estado' => AsistenciaIncidencia::ESTADO_RESUELTA,
                'motivo_resolucion' => $motivoResolucion,
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar(
                $empresa->id, $usuario->id, 'trabajo_en_descanso_sustitutorio', $incidencia,
                $motivoResolucion, $antes, $incidencia->fresh()->toArray(),
            );

            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    private function trabajoEnDescansoCorregirPlanificacion(Empresa $empresa, AsistenciaIncidencia $incidencia, ?AsistenciaHoraExtra $horaExtra, array $datos, User $usuario): AsistenciaIncidencia
    {
        // Bloqueo explícito (Sección 21/Caso 6 del encargo): si la HE 100%
        // ya fue aprobada, corregir la planificación por este camino
        // dejaría esa aprobación desconectada del resultado (que pasaría a
        // minutos_extra_100=0) sin ningún mecanismo de reversión financiera
        // — Agento no tiene ese concepto todavía, así que se bloquea en vez
        // de inventarlo.
        if ($horaExtra && $horaExtra->estado === AsistenciaHoraExtra::ESTADO_APROBADO) {
            throw ValidationException::withMessages([
                'tipo' => ['La hora extra asociada ya fue aprobada. Debe revertirse/corregirse mediante el flujo autorizado antes de modificar la planificación.'],
            ]);
        }

        return DB::transaction(function () use ($empresa, $incidencia, $horaExtra, $datos, $usuario) {
            $antes = $incidencia->toArray();

            if ($horaExtra && $horaExtra->estado === AsistenciaHoraExtra::ESTADO_PENDIENTE) {
                $this->resolverHoraExtra($empresa, $horaExtra, [
                    'accion' => 'rechazar',
                    'motivo' => 'La planificación se corrigió — el día nunca fue realmente un descanso.',
                ], $usuario);
            }

            // Fase 4B — 'incidencia': RR.HH. corrigió a mano una
            // planificación que estaba mal (no era realmente un descanso).
            ColaboradorCalendarioDia::query()->updateOrCreate(
                ['colaborador_id' => $incidencia->colaborador_id, 'fecha' => $incidencia->fecha->toDateString()],
                ['tipo' => $datos['tipo'], 'origen' => ColaboradorCalendarioDia::ORIGEN_INCIDENCIA],
            );
            // El motor normal decide el estado final (presente/HD/HI/etc.)
            // — nunca se fuerza un estado a mano.
            $this->procesador->procesar($incidencia->colaborador, $incidencia->fecha);

            $incidencia->update([
                'estado' => AsistenciaIncidencia::ESTADO_RESUELTA,
                'motivo_resolucion' => $datos['motivo'],
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar(
                $empresa->id, $usuario->id, 'trabajo_en_descanso_corregir_planificacion', $incidencia,
                $datos['motivo'], $antes, $incidencia->fresh()->toArray(),
            );

            return $incidencia->fresh(['colaborador', 'resultado']);
        });
    }

    public function resolverHoraExtra(Empresa $empresa, AsistenciaHoraExtra $horaExtra, array $datos, User $usuario): AsistenciaHoraExtra
    {
        $this->asegurarEmpresa($empresa, $horaExtra);
        $fecha = $horaExtra->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);
        return DB::transaction(function () use ($empresa, $horaExtra, $datos, $usuario) {
            $antes = $horaExtra->toArray();
            $aprobados = $datos['accion'] === 'aprobar'
                ? min($horaExtra->minutos_observados, $datos['minutos_aprobados'] ?? $horaExtra->minutos_observados) : 0;
            // Estado canónico único (V3 Fase 3, mismo bug que ya se corrigió
            // para incidencias en la fase HD/HI): antes se guardaba el verbo
            // crudo de la acción ('rechazar'), nunca el adjetivo. Solo se
            // corrige el flujo NUEVO — los históricos se normalizan aparte
            // en la migración de datos.
            $estado = match ($datos['accion']) {
                'aprobar' => AsistenciaHoraExtra::ESTADO_APROBADO,
                'rechazar' => AsistenciaHoraExtra::ESTADO_RECHAZADO,
                default => $datos['accion'],
            };
            $horaExtra->update([
                'estado' => $estado,
                'minutos_aprobados' => $aprobados, 'motivo' => $datos['motivo'],
                'resuelto_por' => $usuario->id, 'resuelto_at' => now(),
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'hora_extra_'.$datos['accion'], $horaExtra, $datos['motivo'], $antes, $horaExtra->fresh()->toArray());
            return $horaExtra->fresh('colaborador');
        });
    }

    public function resolverSolicitud(Empresa $empresa, AsistenciaSolicitudArea $solicitud, array $datos, User $usuario, bool $esRrhh): AsistenciaSolicitudArea
    {
        $this->asegurarEmpresa($empresa, $solicitud);
        if (! $esRrhh && $usuario->area_id !== $solicitud->area_id) {
            abort(403, 'La solicitud no pertenece al área bajo tu responsabilidad.');
        }
        return DB::transaction(function () use ($empresa, $solicitud, $datos, $usuario, $esRrhh) {
            $antes = $solicitud->toArray();
            if ($datos['accion'] === 'aprobar') {
                $estado = $esRrhh ? 'finalizada' : 'pendiente_rrhh';
            } else {
                $estado = $datos['accion'] === 'observar' ? ($esRrhh ? 'observada_rrhh' : 'observada_responsable') : 'rechazada';
            }
            $solicitud->update($esRrhh ? [
                'estado' => $estado, 'rrhh_por' => $usuario->id, 'rrhh_at' => now(), 'observacion_rrhh' => $datos['motivo'],
            ] : [
                'estado' => $estado, 'responsable_por' => $usuario->id, 'responsable_at' => now(), 'observacion_responsable' => $datos['motivo'],
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'solicitud_'.$datos['accion'], $solicitud, $datos['motivo'], $antes, $solicitud->fresh()->toArray());
            return $solicitud->fresh(['area', 'colaboradores.area']);
        });
    }

    public function resolverPermiso(Empresa $empresa, AsistenciaPermiso $permiso, array $datos, User $usuario): AsistenciaPermiso
    {
        $this->asegurarEmpresa($empresa, $permiso);
        $this->periodos->asegurarRangoEditable(
            $empresa->id, $permiso->fecha_inicio->toDateString(), $permiso->fecha_fin->toDateString()
        );
        $resuelto = DB::transaction(function () use ($empresa, $permiso, $datos, $usuario) {
            $antes = $permiso->toArray();
            $permiso->update([
                'estado' => $datos['accion'] === 'aprobar' ? 'aprobado' : 'rechazado',
                'resuelto_por' => $usuario->id, 'resuelto_at' => now(), 'observacion_resolucion' => $datos['motivo'],
            ]);
            $this->auditoria->registrar($empresa->id, $usuario->id, 'permiso_'.$datos['accion'], $permiso, $datos['motivo'], $antes, $permiso->fresh()->toArray());
            return $permiso->fresh('colaborador.area');
        });
        if ($resuelto->estado === 'aprobado') {
            for ($fecha = Carbon::parse($resuelto->fecha_inicio); $fecha->lte($resuelto->fecha_fin); $fecha->addDay()) {
                $this->procesador->procesar($resuelto->colaborador, $fecha);
            }
        }
        return $resuelto;
    }

    public function editarDia(Empresa $empresa, AsistenciaResultadoDiario $resultado, array $datos, User $usuario): AsistenciaResultadoDiario
    {
        $this->asegurarEmpresa($empresa, $resultado);
        $fecha = $resultado->fecha->toDateString();
        $this->periodos->asegurarRangoEditable($empresa->id, $fecha, $fecha);
        $antes = $resultado->load('marcaciones')->toArray();
        AsistenciaMarcacion::query()->where('empresa_id', $empresa->id)
            ->where('colaborador_id', $resultado->colaborador_id)->where('origen', 'manual_rrhh')
            ->whereDate('marcado_at', $fecha)->whereNull('anulada_at')
            ->update(['anulada_at' => now(), 'anulada_por' => $usuario->id]);
        foreach (['entrada', 'salida'] as $campo) {
            if (! empty($datos[$campo])) {
                AsistenciaMarcacion::query()->firstOrCreate([
                    'empresa_id' => $empresa->id,
                    'person_id' => $resultado->colaborador->numero_documento,
                    'marcado_at' => Carbon::parse($fecha.' '.$datos[$campo]),
                    'origen' => 'manual_rrhh',
                ], ['colaborador_id' => $resultado->colaborador_id, 'dispositivo' => 'Edición RR.HH.']);
            }
        }
        $actualizado = $this->procesador->procesar($resultado->colaborador, $resultado->fecha);
        if (! empty($datos['estado'])) {
            $actualizado->update(['estado' => $datos['estado']]);
            // El estado forzado pisa el que calculó el motor, pero una incidencia
            // de marcación incompleta/horario desplazado/horas incompletas que
            // procesar() dejó pendiente para el estado calculado no se limpiaba
            // sola. Falta y día sin clasificar quedan fuera a propósito: exigen
            // su propio flujo explícito (permiso real o resolverDiaSinClasificar),
            // no deben desaparecer solo porque se forzó el estado del día.
            $this->procesador->limpiarIncidenciasDePresenteForzado($actualizado);
        }
        $this->auditoria->registrar($empresa->id, $usuario->id, 'resultado_editado', $actualizado, $datos['motivo'], $antes, $actualizado->fresh('marcaciones')->toArray());
        return $actualizado->fresh(['colaborador.area', 'incidencias', 'marcaciones']);
    }

    private function asegurarEmpresa(Empresa $empresa, Model $modelo): void
    {
        abort_unless((int) $modelo->getAttribute('empresa_id') === (int) $empresa->id, 404);
    }
}
