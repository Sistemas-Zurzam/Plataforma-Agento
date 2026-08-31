<?php

namespace App\Modules\Personas\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Banco;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorDocumento;
use App\Modules\Personas\Application\AjustarCalendarioPorCambioHorario;
use App\Modules\Personas\Support\CalendarioMensualGenerator;
use App\Modules\Personas\Support\FeriadosPeru;
use App\Modules\Nominas\Services\LiquidacionCeseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;

class ColaboradorService
{
    public function __construct(
        private readonly AjustarCalendarioPorCambioHorario $ajusteCalendario,
        private readonly LiquidacionCeseService $liquidaciones,
    ) {}

    /**
     * @param  array<int, int>  $empresaIds  Ya resueltos y autorizados por el
     *   controller (o [$empresaActiva->id], o todas las de
     *   $usuario->empresas() en modo "todas") — este método nunca decide
     *   autorización, solo filtra.
     * @return LengthAwarePaginator<int, Colaborador>
     */
    public function listar(array $empresaIds, ?string $busqueda, int $perPage = 10): LengthAwarePaginator
    {
        return Colaborador::withTrashed()
            ->whereIn('empresa_id', $empresaIds)
            ->with(['empresa', 'sede', 'area', 'horario', 'remuneracionVigente', 'documentos'])
            ->when($busqueda, fn ($query) => $query->where(function ($query) use ($busqueda) {
                $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('legajo', 'like', "%{$busqueda}%");
            }))
            // Los eliminados van SIEMPRE al final (sin importar su legajo) —
            // "deleted_at IS NOT NULL" da 0 para activos y 1 para eliminados,
            // así que ordenar ascendente por eso agrupa primero los activos.
            // El legajo es el correlativo real y visible para RR.HH.; usar
            // created_at para ordenar fallaba al sembrar/importar varios
            // colaboradores en el mismo segundo (orden de empate arbitrario).
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderByDesc('legajo')
            ->paginate($perPage);
    }

    /**
     * @param  array<int, int>  $empresaIds
     * @return array{total: int, activos: int}
     */
    public function estadisticas(array $empresaIds): array
    {
        $base = Colaborador::whereIn('empresa_id', $empresaIds);

        return [
            'total' => (clone $base)->count(),
            'activos' => (clone $base)->where('activo', true)->count(),
        ];
    }

    /**
     * Colaboradores con horario rotativo que todavía no tienen ningún día
     * de descanso declarado para el mes indicado — señal simple y barata de
     * "rol sin cargar" para avisar ANTES de que alguien intente calcular su
     * planilla y se tope recién ahí con el bloqueo de
     * CalcularBoletaColaborador::verificarRolRotativoCompleto(). No verifica
     * completitud día por día (eso ya lo hace el bloqueo real) — solo si
     * hay al menos un descanso cargado, como aviso temprano.
     *
     * @param  array<int, int>  $empresaIds
     * @return array<int, array{id: int, nombre_completo: string, legajo: string, horario: ?string}>
     */
    public function colaboradoresRotativosSinRol(array $empresaIds, int $anio, int $mes): array
    {
        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->copy()->endOfMonth();

        return Colaborador::whereIn('empresa_id', $empresaIds)
            ->where('activo', true)
            ->whereHas('asignacionesHorario', fn ($query) => $query
                ->whereNull('vigencia_hasta')
                ->whereHas('horario', fn ($query) => $query->where('tipo_turno', 'rotativo')))
            ->whereDoesntHave('calendario', fn ($query) => $query
                ->whereBetween('fecha', [$inicioMes->toDateString(), $finMes->toDateString()])
                ->where('tipo', 'descanso'))
            ->with('horario')
            ->get()
            ->map(fn (Colaborador $colaborador) => [
                'id' => $colaborador->id,
                'nombre_completo' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'legajo' => $colaborador->legajo,
                'horario' => $colaborador->horario?->nombre,
            ])
            ->values()
            ->all();
    }

    public function obtenerDetalle(Empresa $empresa, Colaborador $colaborador): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        return $colaborador->load([
            'empresa', 'sede', 'area', 'horario.dias', 'remuneraciones', 'remuneracionVigente',
            'calendario', 'asignacionesHorario.horario', 'documentos',
        ]);
    }

    public function actualizarCalendario(Empresa $empresa, Colaborador $colaborador, array $dias): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $this->rechazarSiBorraTrabajoEnDescanso($colaborador, $dias);

        DB::transaction(function () use ($colaborador, $dias) {
            foreach ($dias as $dia) {
                // Fase 4B — 'manual' pisa cualquier origen previo (incluido
                // uno automático) a propósito: es exactamente la regla "una
                // decisión humana nueva reemplaza el carácter automático de
                // la fila", tanto si la fecha ya existía como si es nueva
                // (updateOrCreate aplica este array en ambos casos).
                $colaborador->calendario()->updateOrCreate(
                    ['fecha' => $dia['fecha']],
                    ['tipo' => $dia['tipo'], 'origen' => ColaboradorCalendarioDia::ORIGEN_MANUAL],
                );
            }
        });

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    /**
     * Fase 3.2.1 — mismo candado que ya protege
     * PlanificacionRotativaService::planificarDia() (Asistencia): si una
     * fecha ya tenía descanso planificado y además registra trabajo real
     * (minutos_trabajados > 0), este endpoint genérico de calendario no
     * puede pisarla en silencio — antes solo estaba protegida la vía nueva
     * de Planificación, dejando este modal viejo (EditarCalendarioModal)
     * como hueco para borrar la evidencia de "trabajó su descanso". Debe
     * resolverse primero la incidencia especializada
     * (AsistenciaDecisionService::resolverTrabajoEnDescanso()).
     */
    private function rechazarSiBorraTrabajoEnDescanso(Colaborador $colaborador, array $dias): void
    {
        $fechasQueDejanDeSerDescanso = collect($dias)
            ->filter(fn (array $dia) => $dia['tipo'] !== 'descanso')
            ->pluck('fecha')
            ->all();

        if (empty($fechasQueDejanDeSerDescanso)) {
            return;
        }

        $fechasActualmenteDescanso = $colaborador->calendario()
            ->whereIn('fecha', $fechasQueDejanDeSerDescanso)
            ->where('tipo', 'descanso')
            ->get()
            ->map(fn (ColaboradorCalendarioDia $dia) => $dia->fecha->toDateString())
            ->all();

        if (empty($fechasActualmenteDescanso)) {
            return;
        }

        $tieneTrabajoRegistrado = AsistenciaResultadoDiario::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('fecha', $fechasActualmenteDescanso)
            ->where('minutos_trabajados', '>', 0)
            ->exists();

        if ($tieneTrabajoRegistrado) {
            throw ValidationException::withMessages([
                'dias' => ['Una o más fechas registran trabajo sobre un día de descanso. Resuelve primero la incidencia "Trabajo en descanso" desde Asistencia.'],
            ]);
        }
    }

    /**
     * Devuelve el calendario de un mes puntual de un colaborador ya
     * existente. Si ese mes todavía no tiene filas propias (nunca se editó
     * y no es el mes de ingreso), se genera heredando el patrón por día de
     * semana del mes anterior con datos — ver CalendarioMensualGenerator.
     */
    public function calendarioDelMes(Empresa $empresa, Colaborador $colaborador, int $anio, int $mes): array
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $dias = CalendarioMensualGenerator::paraMes($colaborador, $anio, $mes);

        return [
            'dias' => $dias->map(fn ($dia) => [
                'fecha' => $dia->fecha->toDateString(),
                'tipo' => $dia->tipo,
                'editable' => true,
                // false = instancia virtual sin guardar (horario rotativo
                // sin ese día declarado todavía) — permite a la UI marcar
                // visualmente qué días todavía no tienen un rol confirmado.
                'declarado' => $dia->exists,
            ])->values()->all(),
        ];
    }

    /**
     * Fase 4C — puede devolver un array en vez de Colaborador cuando el
     * cambio afectaría planificación humana/legacy futura y todavía no
     * llegó confirmación explícita (`$confirmarPlanificacionExistente`):
     * `['requiere_confirmacion' => true, 'impacto' => [...]]`. El
     * controller detecta esa forma y responde 409 en vez de la Resource
     * normal — nada se modifica todavía en ese caso.
     *
     * @param  array{horario_id:int, modalidad_trabajo:string, tolerancia_particular_minutos:?int, vigencia_desde:string, vigencia_hasta:?string}  $datos
     * @return Colaborador|array{requiere_confirmacion: true, impacto: array<string, mixed>}
     */
    public function actualizarHorario(Empresa $empresa, Colaborador $colaborador, array $datos, int $usuarioId, bool $confirmarPlanificacionExistente = false): Colaborador|array
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $vigenciaDesde = $datos['vigencia_desde'];
        $vigenciaHasta = $datos['vigencia_hasta'] ?? null;

        // Horarios es un catálogo global (compartido entre empresas de un
        // mismo grupo) — solo se valida que esté activo y vigente, no que
        // pertenezca a esta empresa.
        $horarioValido = Horario::whereKey($datos['horario_id'])
            ->where('activo', true)
            ->whereDate('vigencia_desde', '<=', $vigenciaDesde)
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $vigenciaDesde))
            ->exists();

        if (! $horarioValido) {
            throw new AuthorizationException('El horario seleccionado no está activo o vigente para la fecha indicada.');
        }

        $asignacionActual = $colaborador->asignacionesHorario()->whereNull('vigencia_hasta')->first();
        $diasDescansoNuevo = $datos['dias_descanso_rotativo_por_semana'] ?? null;
        // También versiona si SOLO cambia el número de días de descanso
        // rotativo (sin cambiar de horario) — igual que el horario
        // mismo, no se sobrescribe en el sitio si afecta el cálculo de
        // fechas ya pasadas.
        $requiereNuevaVigencia = $colaborador->horario_id !== $datos['horario_id']
            || $asignacionActual?->dias_descanso_rotativo_por_semana !== $diasDescansoNuevo;

        $impacto = null;
        if ($requiereNuevaVigencia) {
            // Fase 4C — antes de tocar nada: ¿ya existen resultados de
            // asistencia procesados desde esta vigencia? Un cambio
            // retroactivo sobre historial ya procesado no se invalida
            // automáticamente (corrección manual especializada, ver
            // AjustarCalendarioPorCambioHorario::evaluarImpacto()).
            $impacto = $this->ajusteCalendario->evaluarImpacto($colaborador, $vigenciaDesde);

            if ($impacto['bloqueado_por_procesado']) {
                throw ValidationException::withMessages([
                    'vigencia_desde' => ['Ya existen resultados de asistencia procesados desde esta fecha — un cambio de horario retroactivo sobre historial ya procesado requiere corrección manual especializada, no se aplica automáticamente.'],
                ]);
            }

            $this->ajusteCalendario->asegurarSinPeriodoProtegido($empresa->id, $vigenciaDesde);

            if ($impacto['requiere_confirmacion'] && ! $confirmarPlanificacionExistente) {
                return ['requiere_confirmacion' => true, 'impacto' => $impacto];
            }
        }

        DB::transaction(function () use ($colaborador, $datos, $empresa, $vigenciaDesde, $vigenciaHasta, $requiereNuevaVigencia, $asignacionActual, $diasDescansoNuevo, $impacto, $usuarioId) {
            if ($requiereNuevaVigencia) {
                // Fase 4C — invalida solo lo automático (selección positiva
                // exacta), conserva feriados/humano/legacy siempre.
                $this->ajusteCalendario->invalidarAutomaticas(
                    $empresa, $colaborador, $vigenciaDesde, $impacto, $usuarioId,
                    $colaborador->horario_id, $datos['horario_id'],
                );

                // Corrección (Sección 12): la fecha indicada no es posterior
                // a la vigencia ya abierta -> se corrige el mismo registro
                // en vez de fragmentar el historial con una vigencia nueva.
                if ($asignacionActual && $vigenciaDesde <= $asignacionActual->vigencia_desde->toDateString()) {
                    $asignacionActual->update([
                        'horario_id' => $datos['horario_id'],
                        'dias_descanso_rotativo_por_semana' => $diasDescansoNuevo,
                        'vigencia_desde' => $vigenciaDesde,
                        'vigencia_hasta' => $vigenciaHasta,
                    ]);
                } else {
                    $asignacionActual?->update([
                        'vigencia_hasta' => Carbon::parse($vigenciaDesde)->subDay()->toDateString(),
                    ]);
                    $colaborador->asignacionesHorario()->create([
                        'empresa_id' => $empresa->id,
                        'horario_id' => $datos['horario_id'],
                        'dias_descanso_rotativo_por_semana' => $diasDescansoNuevo,
                        'vigencia_desde' => $vigenciaDesde,
                        'vigencia_hasta' => $vigenciaHasta,
                    ]);
                }
            }

            $colaborador->update([
                'horario_id' => $datos['horario_id'],
                'modalidad_trabajo' => $datos['modalidad_trabajo'],
                'tolerancia_particular_minutos' => $datos['tolerancia_particular_minutos'] ?? null,
            ]);
        });

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    public function actualizar(Empresa $empresa, Colaborador $colaborador, array $datos): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $sedeValida = Sede::whereKey($datos['sede_id'])->where('empresa_id', $empresa->id)->where('activa', true)->exists();
        $areaValida = Area::whereKey($datos['area_id'])->where('empresa_id', $empresa->id)->exists();
        if (! $sedeValida || ! $areaValida) {
            throw new AuthorizationException('La sede o área no pertenece a la empresa activa.');
        }

        // V3 P3 — si se está desactivando la condición de confianza y el
        // colaborador no tiene horario asignado, no se puede dejarlo así:
        // sin horario y sin confianza, Asistencia no podría procesarlo.
        // No se auto-asigna ninguno — se le pide a RR.HH. que lo asigne
        // primero vía "Asignar horario".
        if (array_key_exists('es_trabajador_confianza', $datos)
            && ! $datos['es_trabajador_confianza']
            && $colaborador->es_trabajador_confianza
            && ! $colaborador->horario_id) {
            throw new AuthorizationException('Este colaborador no tiene horario asignado — asígnale uno antes de quitarle la condición de trabajador de confianza.');
        }

        $apellidoPaterno = mb_strtoupper(trim($datos['apellido_paterno']), 'UTF-8');
        $apellidoMaterno = mb_strtoupper(trim($datos['apellido_materno'] ?? ''), 'UTF-8');

        $colaborador->update([
            ...$datos,
            'nombres' => mb_strtoupper(trim($datos['nombres']), 'UTF-8'),
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno !== '' ? $apellidoMaterno : null,
            // Derivado — ver crear(): nunca se pide directamente al usuario.
            'apellidos' => trim("{$apellidoPaterno} {$apellidoMaterno}"),
            'banco_id' => array_key_exists('banco', $datos) ? $this->resolverBancoId($datos['banco']) : $colaborador->banco_id,
        ]);

        $this->registrarCondicionLaboralSiCambio($colaborador);

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    /**
     * Registra una nueva remuneración vigente. NUNCA actualiza la fila
     * anterior — el historial remunerativo se preserva insertando siempre
     * una fila nueva con su propia vigencia_desde (mismo patrón que
     * parametro_laboral_valores/comisiones_afp). Una boleta calculada para
     * agosto sigue usando el sueldo vigente en agosto aunque hoy ya haya
     * cambiado.
     */
    public function actualizarRemuneracion(Empresa $empresa, Colaborador $colaborador, array $datos): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $vigente = $colaborador->remuneraciones()->orderByDesc('vigencia_desde')->orderByDesc('id')->first();

        if ($vigente && $datos['vigencia_desde'] < $vigente->vigencia_desde->toDateString()) {
            throw new AuthorizationException('La nueva vigencia no puede ser anterior a la remuneración vigente actual.');
        }

        $colaborador->remuneraciones()->create([
            'salario' => $datos['salario'],
            'moneda_salario' => $datos['moneda_salario'] ?? $vigente?->moneda_salario ?? 'PEN',
            'periodicidad_pago' => $datos['periodicidad_pago'] ?? $vigente?->periodicidad_pago ?? 'mensual',
            'asignacion_familiar' => $datos['asignacion_familiar'] ?? 0,
            'vigencia_desde' => $datos['vigencia_desde'],
        ]);

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    /**
     * Configuración previsional del colaborador para Remuneraciones —
     * deliberadamente SEPARADA de update() (datos personales/contractuales):
     * este método solo toca campos propios del colaborador (ONP/AFP/tipo de
     * comisión/CUSPP/asignación familiar), nunca parámetros legales
     * nacionales (esos viven en ParametroLaboralValor, nunca aquí).
     * ONP y AFP son mutuamente excluyentes; cambiar de uno a otro no borra
     * boletas históricas porque éstas ya tienen su propio snapshot.
     *
     * Un locador (régimen "Locacion de Servicios") no tiene ONP/AFP — usa
     * en su lugar tiene_suspension_renta_4ta, propio del motor de Recibos
     * por Honorarios.
     */
    public function actualizarConfiguracionNomina(Empresa $empresa, Colaborador $colaborador, array $datos): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $esHonorarios = ($datos['regimen_laboral'] ?? $colaborador->regimen_laboral) === 'Locacion de Servicios';
        $esOnp = ($datos['sistema_previsional'] ?? null) === 'onp';

        $colaborador->update([
            'regimen_laboral' => $datos['regimen_laboral'] ?? $colaborador->regimen_laboral,
            'sistema_previsional' => $esHonorarios ? null : $datos['sistema_previsional'],
            'afp_id' => ($esHonorarios || $esOnp) ? null : $datos['afp_id'],
            'tipo_comision' => ($esHonorarios || $esOnp) ? null : $datos['tipo_comision'],
            'cuspp' => ($esHonorarios || $esOnp) ? null : $datos['cuspp'],
            'tiene_hijos_asignacion_familiar' => $esHonorarios ? false : ($datos['tiene_hijos_asignacion_familiar'] ?? false),
            'tiene_suspension_renta_4ta' => $esHonorarios ? ($datos['tiene_suspension_renta_4ta'] ?? false) : false,
        ]);

        $this->registrarCondicionLaboralSiCambio($colaborador);

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    /**
     * PLAME necesita reconstruir régimen laboral, tipo de contrato y sistema
     * previsional TAL COMO estaban en cada período pasado (T-Registro es
     * histórico), pero el motor de cálculo (CalcularBoletaColaborador /
     * CalcularReciboHonorarios) sigue leyendo estas columnas directamente
     * desde Colaborador — no se toca ese contrato. Esta tabla es solo un
     * registro de auditoría paralelo: se inserta una fila nueva cada vez que
     * algo relevante cambia, nunca se sobrescribe una existente.
     */
    private function registrarCondicionLaboralSiCambio(Colaborador $colaborador, ?string $vigenciaDesde = null): void
    {
        $colaborador->refresh();

        $actual = [
            'regimen_laboral' => $colaborador->regimen_laboral,
            'tipo_contrato' => $colaborador->tipo_contrato,
            'categoria_trabajador' => $colaborador->categoria_trabajador,
            'sistema_previsional' => $colaborador->sistema_previsional,
            'afp_id' => $colaborador->afp_id,
            'tipo_comision' => $colaborador->tipo_comision,
            // V3 P3/T1 — historizado igual que el resto: una nómina de mayo
            // debe poder reconstruir si el colaborador era o no de confianza
            // en mayo, sin importar el valor actual.
            'es_trabajador_confianza' => $colaborador->es_trabajador_confianza,
            // V3 P2/T1 — mismo criterio: afecta directamente el descuento
            // por tardanza, un cambio a mitad de mes no debe reinterpretar
            // boletas ya calculadas con otras fechas.
            'contabilizar_tardanzas' => $colaborador->contabilizar_tardanzas,
            'contabilizar_faltas' => $colaborador->contabilizar_faltas,
            'contabilizar_horas_extra' => $colaborador->contabilizar_horas_extra,
        ];

        $vigente = $colaborador->condicionesLaborales()->orderByDesc('vigencia_desde')->orderByDesc('id')->first();

        if ($vigente && $actual == $vigente->only(array_keys($actual))) {
            return;
        }

        $colaborador->condicionesLaborales()->create([
            ...$actual,
            'vigencia_desde' => $vigenciaDesde ?? now()->toDateString(),
        ]);
    }

    public function cesar(Empresa $empresa, Colaborador $colaborador, string $fechaCese, string $motivo, array $seleccion, int $usuarioId): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }
        if (! collect($seleccion)->contains(true)) {
            throw ValidationException::withMessages(['conceptos' => 'Selecciona al menos un concepto para generar la liquidación.']);
        }

        DB::transaction(function () use ($empresa, $colaborador, $fechaCese, $motivo, $seleccion, $usuarioId) {
            $bloqueado = Colaborador::whereKey($colaborador->id)->lockForUpdate()->firstOrFail();
            if (! $bloqueado->activo || $bloqueado->fecha_cese) {
                throw ValidationException::withMessages(['colaborador' => 'El colaborador ya fue cesado.']);
            }
            // El snapshot se calcula antes de inactivar/cerrar vigencias, pero
            // se confirma en la misma transacción que el cese.
            $this->liquidaciones->guardar($empresa, $bloqueado, $fechaCese, $motivo, $seleccion, $usuarioId);
            $bloqueado->update([
                'activo' => false,
                'fecha_cese' => $fechaCese,
                'motivo_cese' => $motivo,
                'fecha_fin_contrato' => $colaborador->fecha_fin_contrato ?? $fechaCese,
            ]);
            $bloqueado->asignacionesHorario()
                ->whereNull('vigencia_hasta')
                ->update(['vigencia_hasta' => $fechaCese]);
        });

        // El bloqueo/actualización dentro de la transacción ocurre sobre
        // $bloqueado (una instancia distinta obtenida con lockForUpdate) —
        // sin este refresh, $colaborador queda con los atributos de ANTES
        // del cese (activo=true, sin fecha_cese) y el Resource devuelto al
        // frontend mentiría sobre el resultado de una operación que sí tuvo
        // éxito en base de datos.
        $colaborador->refresh();

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    public function eliminar(Empresa $empresa, Colaborador $colaborador): void
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $colaborador->delete();
    }

    /**
     * Trae de vuelta a un colaborador eliminado (soft delete) — recupera
     * TODO su historial (legajo, remuneraciones, boletas) porque nunca se
     * borró de verdad. Se trata como si la persona regresara a trabajar:
     * también lo reactiva y limpia cualquier cese previo, en vez de dejarlo
     * restaurado pero inactivo.
     *
     * @throws AuthorizationException
     */
    public function restaurar(Empresa $empresa, int $colaboradorId): Colaborador
    {
        $colaborador = Colaborador::onlyTrashed()
            ->where('empresa_id', $empresa->id)
            ->find($colaboradorId);

        if (! $colaborador) {
            throw new AuthorizationException('No hay ningún colaborador eliminado con ese id en esta empresa.');
        }

        DB::transaction(function () use ($colaborador) {
            $colaborador->restore();
            $colaborador->update([
                'activo' => true,
                'fecha_cese' => null,
                'motivo_cese' => null,
            ]);
        });

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    public function guardarDocumento(
        Empresa $empresa,
        Colaborador $colaborador,
        string $tipo,
        UploadedFile $archivo,
        User $usuario,
    ): Colaborador {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $ruta = $archivo->store("colaboradores/{$colaborador->id}/legajo", 'local');
        $anterior = $colaborador->documentos()->where('tipo', $tipo)->first();

        try {
            $colaborador->documentos()->updateOrCreate(['tipo' => $tipo], [
                'nombre_original' => $archivo->getClientOriginalName(),
                'ruta' => $ruta,
                'mime_type' => $archivo->getMimeType() ?? 'application/octet-stream',
                'tamano_bytes' => $archivo->getSize(),
                'subido_por' => $usuario->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($ruta);
            throw $exception;
        }

        if ($anterior && $anterior->ruta !== $ruta) {
            Storage::disk('local')->delete($anterior->ruta);
        }

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    /**
     * A diferencia de los documentos del legajo (que deben conservarse
     * fieles al original, ej. una copia de DNI escaneada), la foto de
     * perfil SÍ se redimensiona y recomprime a WebP antes de guardarse —
     * mismo criterio que Empresa::guardarLogo(), pero acá el archivo queda
     * en el disco privado "local" (dato personal, servido por descarga
     * autenticada), nunca en el disco público.
     */
    public function guardarFotoPerfil(Empresa $empresa, Colaborador $colaborador, UploadedFile $archivo, User $usuario): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $anterior = $colaborador->documentos()->where('tipo', 'foto_perfil')->first();
        $carpeta = "colaboradores/{$colaborador->id}/foto_perfil";
        $ruta = "{$carpeta}/".Str::random(20).'.webp';

        Storage::disk('local')->makeDirectory($carpeta);

        ImageManager::gd()
            ->read($archivo->getRealPath())
            ->scaleDown(width: 512, height: 512)
            ->save(Storage::disk('local')->path($ruta), quality: 80);

        try {
            $colaborador->documentos()->updateOrCreate(['tipo' => 'foto_perfil'], [
                'nombre_original' => 'foto_perfil.webp',
                'ruta' => $ruta,
                'mime_type' => 'image/webp',
                'tamano_bytes' => Storage::disk('local')->size($ruta),
                'subido_por' => $usuario->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($ruta);
            throw $exception;
        }

        if ($anterior && $anterior->ruta !== $ruta) {
            Storage::disk('local')->delete($anterior->ruta);
        }

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    public function obtenerFotoPerfil(Empresa $empresa, Colaborador $colaborador): ?ColaboradorDocumento
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        return $colaborador->documentos()->where('tipo', 'foto_perfil')->first();
    }

    /**
     * Arma el calendario del mes de `fechaIngreso` con un tipo por defecto
     * por día: feriado oficial (fijo, no editable) > día de semana en
     * "descanso" según el horario > laborable presencial. Los días
     * anteriores a fechaIngreso quedan marcados como no editables (el
     * frontend no los envía al crear el colaborador).
     *
     * @return array{dias: array<int, array{fecha: string, tipo: string, editable: bool}>}
     *
     * @throws AuthorizationException
     */
    public function calendarioPorDefecto(Horario $horario, string $fechaIngreso): array
    {
        $fecha = Carbon::parse($fechaIngreso)->startOfDay();
        // Horarios es un catálogo global — no se restringe por empresa_id,
        // solo se valida que esté activo y vigente para la fecha.
        if (
            ! $horario->activo
            || $horario->vigencia_desde?->startOfDay()->gt($fecha)
            || $horario->vigencia_hasta?->endOfDay()->lt($fecha)
        ) {
            throw new AuthorizationException('El horario indicado no está activo o vigente para la fecha de ingreso.');
        }

        $horario->loadMissing('dias');
        $diasPorSemana = $horario->dias->keyBy('dia_semana');

        $fechaIngresoCarbon = Carbon::parse($fechaIngreso)->startOfDay();
        $inicioMes = $fechaIngresoCarbon->copy()->startOfMonth();
        $finMes = $fechaIngresoCarbon->copy()->endOfMonth();

        $dias = [];
        for ($fecha = $inicioMes->copy(); $fecha->lte($finMes); $fecha->addDay()) {
            $fechaTexto = $fecha->toDateString();
            $esFeriado = FeriadosPeru::esFeriado($fechaTexto);

            // Rotativo Fase 1 — un horario rotativo NUNCA propone laborable
            // ni descanso por defecto: no tiene un patrón semanal fijo, y
            // "proponer laborable para que RR.HH. lo corrija" terminaba
            // persistiéndose tal cual si nadie tocaba esa fecha (justo la
            // inferencia que la auditoría pidió eliminar). Feriado SÍ se
            // propone — es un hecho legal fijo para todos, no una
            // suposición sobre el patrón de descanso de esta persona en
            // particular. El resto de fechas simplemente no se incluye:
            // ausencia de fila = fecha todavía no planificada.
            if ($horario->tipo_turno === 'rotativo' && ! $esFeriado) {
                continue;
            }

            if ($esFeriado) {
                $tipo = 'feriado';
            } else {
                // dia_semana en Horario: 0=Lunes...6=Domingo; dayOfWeekIso: 1=Lunes...7=Domingo.
                $horarioDia = $diasPorSemana->get($fecha->dayOfWeekIso - 1);
                $tipo = ($horarioDia && $horarioDia->estado === 'descanso') ? 'descanso' : 'laborable_presencial';
            }

            $dias[] = [
                'fecha' => $fechaTexto,
                'tipo' => $tipo,
                'editable' => $fecha->gte($fechaIngresoCarbon),
            ];
        }

        return ['dias' => $dias];
    }

    /**
     * Crea el colaborador, su primera remuneración y su calendario inicial
     * en una sola transacción. Verifica que sede/área/horario elegidos
     * pertenezcan a la misma empresa activa (mismo patrón que
     * SedeService/HorarioService).
     *
     * @throws AuthorizationException
     */
    public function crear(Empresa $empresa, array $datos): Colaborador
    {
        if (! $empresa->activa) {
            throw new AuthorizationException('No puedes registrar colaboradores en una empresa inactiva.');
        }

        $this->verificarPertenenciaDeReferencias(
            $empresa,
            $datos['sede_id'],
            $datos['area_id'],
            $datos['horario_id'] ?? null,
            $datos['fecha_ingreso'],
        );

        return DB::transaction(function () use ($empresa, $datos) {
            $colaborador = Colaborador::create([
                ...collect($datos)
                    ->except(['salario', 'moneda_salario', 'periodicidad_pago', 'asignacion_familiar', 'calendario'])
                    ->all(),
                'empresa_id' => $empresa->id,
                'legajo' => $this->siguienteLegajo($empresa),
                // Identidad confiable de banco para Telecrédito (nunca se le
                // pide al usuario que la llene aparte) — ver resolverBancoId().
                'banco_id' => $this->resolverBancoId($datos['banco'] ?? null),
                // "apellidos" queda como campo derivado (nunca se pide
                // directamente al usuario) para no romper a los muchos
                // consumidores que todavía lo leen tal cual (carnet, ficha,
                // boletas, resource) — la fuente de verdad real ya es
                // apellido_paterno/apellido_materno.
                'apellidos' => trim(($datos['apellido_paterno'] ?? '').' '.($datos['apellido_materno'] ?? '')),
            ]);

            $colaborador->remuneraciones()->create([
                'salario' => $datos['salario'],
                'moneda_salario' => $datos['moneda_salario'],
                'periodicidad_pago' => $datos['periodicidad_pago'],
                'asignacion_familiar' => $datos['asignacion_familiar'] ?? 0,
                'vigencia_desde' => $datos['fecha_ingreso'],
            ]);

            $colaborador->condicionesLaborales()->create([
                'regimen_laboral' => $colaborador->regimen_laboral,
                'tipo_contrato' => $colaborador->tipo_contrato,
                'categoria_trabajador' => $colaborador->categoria_trabajador,
                'sistema_previsional' => $colaborador->sistema_previsional,
                'afp_id' => $colaborador->afp_id,
                'tipo_comision' => $colaborador->tipo_comision,
                'es_trabajador_confianza' => $colaborador->es_trabajador_confianza,
                // V3 P2/T1 — mismo tratamiento histórico que es_trabajador_confianza.
                'contabilizar_tardanzas' => $colaborador->contabilizar_tardanzas,
                'contabilizar_faltas' => $colaborador->contabilizar_faltas,
                'contabilizar_horas_extra' => $colaborador->contabilizar_horas_extra,
                'vigencia_desde' => $datos['fecha_ingreso'],
            ]);

            // Sin horario_id no hay nada que asignar (V3 P3: trabajador de
            // confianza puede no tenerlo) — se omite la fila en vez de
            // forzar un valor.
            if ($datos['horario_id'] ?? null) {
                $colaborador->asignacionesHorario()->create([
                    'empresa_id' => $empresa->id,
                    'horario_id' => $datos['horario_id'],
                    'dias_descanso_rotativo_por_semana' => $datos['dias_descanso_rotativo_por_semana'] ?? null,
                    'vigencia_desde' => $datos['fecha_ingreso'],
                    'vigencia_hasta' => null,
                ]);
            }

            // Se descarta cualquier día anterior a fecha_ingreso por
            // defensividad, aunque el frontend ya no debería enviarlos.
            // Fase 4B — origen 'wizard': RR.HH. revisó y confirmó este
            // calendario en el Paso 2 del alta (pudo editarlo ahí), es una
            // ruta de escritura propia, distinta de CalendarioMensualGenerator.
            collect($datos['calendario'] ?? [])
                ->filter(fn (array $dia) => $dia['fecha'] >= $datos['fecha_ingreso'])
                ->each(fn (array $dia) => $colaborador->calendario()->create([
                    'fecha' => $dia['fecha'],
                    'tipo' => $dia['tipo'],
                    'origen' => ColaboradorCalendarioDia::ORIGEN_WIZARD,
                ]));

            return $colaborador->load([
                'sede', 'area', 'horario', 'remuneraciones', 'remuneracionVigente', 'calendario', 'asignacionesHorario',
            ]);
        });
    }

    private function siguienteLegajo(Empresa $empresa): string
    {
        // withTrashed(): un colaborador eliminado (soft delete) sigue ocupando su
        // legajo en el índice único de la tabla — si no se cuenta acá, el próximo
        // número generado choca contra el suyo y la inserción falla en la BD.
        $ultimoNumero = Colaborador::withTrashed()->where('empresa_id', $empresa->id)
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(legajo, '-', -1) AS UNSIGNED)) as maximo")
            ->value('maximo');

        return 'LEG-'.str_pad((int) $ultimoNumero + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Resuelve `banco_id` desde el string libre `banco` (Sección 6 de la
     * preparación Telecrédito) — coincidencia EXACTA contra `bancos.nombre`
     * (los mismos valores de BANCO_OPTIONS en el frontend). Nunca adivina:
     * un valor como "Otro" o cualquiera que no coincida exacto queda en
     * NULL — Telecrédito lo reportará como banco no identificable, no se
     * asume ninguno.
     */
    private function resolverBancoId(?string $banco): ?int
    {
        if (blank($banco)) {
            return null;
        }

        return Banco::where('nombre', $banco)->where('activo', true)->value('id');
    }

    /**
     * @throws AuthorizationException
     */
    private function verificarPertenenciaDeReferencias(
        Empresa $empresa,
        int $sedeId,
        int $areaId,
        ?int $horarioId,
        string $fechaIngreso,
    ): void
    {
        $sedeValida = Sede::where('id', $sedeId)
            ->where('empresa_id', $empresa->id)
            ->where('activa', true)
            ->exists();
        $areaValida = Area::where('id', $areaId)->where('empresa_id', $empresa->id)->exists();

        if (! $sedeValida || ! $areaValida) {
            throw new AuthorizationException('La sede o área indicadas no pertenecen a la empresa activa.');
        }

        // Sin horario_id no hay nada que validar acá (V3 P3: trabajador de
        // confianza puede no tenerlo) — StoreColaboradorRequest ya garantiza
        // que solo llega null cuando es_trabajador_confianza=true.
        if ($horarioId === null) {
            return;
        }

        // Horarios es un catálogo global (compartido entre empresas de un
        // mismo grupo) — no se restringe a esta empresa, solo se valida que
        // esté activo y vigente para la fecha de ingreso.
        $horarioValido = Horario::where('id', $horarioId)
            ->where('activo', true)
            ->whereDate('vigencia_desde', '<=', $fechaIngreso)
            ->where(fn ($query) => $query
                ->whereNull('vigencia_hasta')
                ->orWhereDate('vigencia_hasta', '>=', $fechaIngreso))
            ->exists();

        if (! $horarioValido) {
            throw new AuthorizationException('El horario indicado no está activo o vigente para la fecha de ingreso.');
        }
    }
}
