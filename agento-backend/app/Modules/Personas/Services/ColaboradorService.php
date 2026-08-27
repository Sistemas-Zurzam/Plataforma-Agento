<?php

namespace App\Modules\Personas\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\Horario;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Sede;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorDocumento;
use App\Modules\Personas\Support\CalendarioMensualGenerator;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;

class ColaboradorService
{
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
            ->with(['empresa', 'sede', 'area', 'horario', 'remuneracionVigente'])
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

        DB::transaction(function () use ($colaborador, $dias) {
            foreach ($dias as $dia) {
                $colaborador->calendario()->updateOrCreate(
                    ['fecha' => $dia['fecha']],
                    ['tipo' => $dia['tipo']],
                );
            }
        });

        return $this->obtenerDetalle($empresa, $colaborador);
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
     * @param  array{horario_id:int, modalidad_trabajo:string, tolerancia_particular_minutos:?int, vigencia_desde:string, vigencia_hasta:?string}  $datos
     */
    public function actualizarHorario(Empresa $empresa, Colaborador $colaborador, array $datos): Colaborador
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

        DB::transaction(function () use ($colaborador, $datos, $empresa, $vigenciaDesde, $vigenciaHasta) {
            $asignacionActual = $colaborador->asignacionesHorario()->whereNull('vigencia_hasta')->first();
            $diasDescansoNuevo = $datos['dias_descanso_rotativo_por_semana'] ?? null;
            // También versiona si SOLO cambia el número de días de descanso
            // rotativo (sin cambiar de horario) — igual que el horario
            // mismo, no se sobrescribe en el sitio si afecta el cálculo de
            // fechas ya pasadas.
            $requiereNuevaVigencia = $colaborador->horario_id !== $datos['horario_id']
                || $asignacionActual?->dias_descanso_rotativo_por_semana !== $diasDescansoNuevo;

            if ($requiereNuevaVigencia) {
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

        $colaborador->update([
            ...$datos,
            'nombres' => mb_strtoupper(trim($datos['nombres']), 'UTF-8'),
            'apellidos' => mb_strtoupper(trim($datos['apellidos']), 'UTF-8'),
        ]);

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

        return $this->obtenerDetalle($empresa, $colaborador);
    }

    public function cesar(Empresa $empresa, Colaborador $colaborador, string $fechaCese, string $motivo): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        DB::transaction(function () use ($colaborador, $fechaCese, $motivo) {
            $colaborador->update([
                'activo' => false,
                'fecha_cese' => $fechaCese,
                'motivo_cese' => $motivo,
                'fecha_fin_contrato' => $colaborador->fecha_fin_contrato ?? $fechaCese,
            ]);
            $colaborador->asignacionesHorario()
                ->whereNull('vigencia_hasta')
                ->update(['vigencia_hasta' => $fechaCese]);
        });

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

            if (FeriadosPeru::esFeriado($fechaTexto)) {
                $tipo = 'feriado';
            } elseif ($horario->tipo_turno === 'rotativo') {
                // Un horario rotativo no tiene un patrón semanal fijo de
                // descanso -- se propone laborable en todos los días para
                // que RR.HH. marque a mano cuáles son los de descanso real
                // (Sección: rotativos, cero inferencia).
                $tipo = 'laborable_presencial';
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
            $datos['horario_id'],
            $datos['fecha_ingreso'],
        );

        return DB::transaction(function () use ($empresa, $datos) {
            $colaborador = Colaborador::create([
                ...collect($datos)
                    ->except(['salario', 'moneda_salario', 'periodicidad_pago', 'asignacion_familiar', 'calendario'])
                    ->all(),
                'empresa_id' => $empresa->id,
                'legajo' => $this->siguienteLegajo($empresa),
            ]);

            $colaborador->remuneraciones()->create([
                'salario' => $datos['salario'],
                'moneda_salario' => $datos['moneda_salario'],
                'periodicidad_pago' => $datos['periodicidad_pago'],
                'asignacion_familiar' => $datos['asignacion_familiar'] ?? 0,
                'vigencia_desde' => $datos['fecha_ingreso'],
            ]);

            $colaborador->asignacionesHorario()->create([
                'empresa_id' => $empresa->id,
                'horario_id' => $datos['horario_id'],
                'dias_descanso_rotativo_por_semana' => $datos['dias_descanso_rotativo_por_semana'] ?? null,
                'vigencia_desde' => $datos['fecha_ingreso'],
                'vigencia_hasta' => null,
            ]);

            // Se descarta cualquier día anterior a fecha_ingreso por
            // defensividad, aunque el frontend ya no debería enviarlos.
            collect($datos['calendario'] ?? [])
                ->filter(fn (array $dia) => $dia['fecha'] >= $datos['fecha_ingreso'])
                ->each(fn (array $dia) => $colaborador->calendario()->create([
                    'fecha' => $dia['fecha'],
                    'tipo' => $dia['tipo'],
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
     * @throws AuthorizationException
     */
    private function verificarPertenenciaDeReferencias(
        Empresa $empresa,
        int $sedeId,
        int $areaId,
        int $horarioId,
        string $fechaIngreso,
    ): void
    {
        $sedeValida = Sede::where('id', $sedeId)
            ->where('empresa_id', $empresa->id)
            ->where('activa', true)
            ->exists();
        $areaValida = Area::where('id', $areaId)->where('empresa_id', $empresa->id)->exists();
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

        if (! $sedeValida || ! $areaValida) {
            throw new AuthorizationException('La sede o área indicadas no pertenecen a la empresa activa.');
        }
        if (! $horarioValido) {
            throw new AuthorizationException('El horario indicado no está activo o vigente para la fecha de ingreso.');
        }
    }
}
