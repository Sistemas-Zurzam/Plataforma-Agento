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
        return Colaborador::whereIn('empresa_id', $empresaIds)
            ->with(['empresa', 'sede', 'area', 'horario', 'remuneracionVigente'])
            ->when($busqueda, fn ($query) => $query->where(function ($query) use ($busqueda) {
                $query->where('nombres', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('legajo', 'like', "%{$busqueda}%");
            }))
            // El legajo es el correlativo real y visible para RR.HH.; usar
            // created_at para ordenar fallaba al sembrar/importar varios
            // colaboradores en el mismo segundo (orden de empate arbitrario).
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
            ])->values()->all(),
        ];
    }

    public function actualizarHorario(Empresa $empresa, Colaborador $colaborador, array $datos): Colaborador
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw new AuthorizationException('Este colaborador no pertenece a la empresa activa.');
        }

        $hoy = now()->toDateString();
        $horarioValido = Horario::whereKey($datos['horario_id'])
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereDate('vigencia_desde', '<=', $hoy)
            ->where(fn ($query) => $query->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $hoy))
            ->exists();

        if (! $horarioValido) {
            throw new AuthorizationException('El horario seleccionado no está activo o vigente para la empresa.');
        }

        DB::transaction(function () use ($colaborador, $datos, $empresa, $hoy) {
            if ($colaborador->horario_id !== $datos['horario_id']) {
                $asignacionActual = $colaborador->asignacionesHorario()->whereNull('vigencia_hasta')->first();
                if ($asignacionActual?->vigencia_desde?->toDateString() === $hoy) {
                    $asignacionActual->update(['horario_id' => $datos['horario_id']]);
                } else {
                    $asignacionActual?->update(['vigencia_hasta' => now()->subDay()->toDateString()]);
                    $colaborador->asignacionesHorario()->create([
                        'empresa_id' => $empresa->id,
                        'horario_id' => $datos['horario_id'],
                        'vigencia_desde' => $hoy,
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
    public function calendarioPorDefecto(Empresa $empresa, Horario $horario, string $fechaIngreso): array
    {
        $fecha = Carbon::parse($fechaIngreso)->startOfDay();
        if (
            $horario->empresa_id !== $empresa->id
            || ! $horario->activo
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
        $horarioValido = Horario::where('id', $horarioId)
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereDate('vigencia_desde', '<=', $fechaIngreso)
            ->where(fn ($query) => $query
                ->whereNull('vigencia_hasta')
                ->orWhereDate('vigencia_hasta', '>=', $fechaIngreso))
            ->exists();

        if (! $sedeValida || ! $areaValida || ! $horarioValido) {
            throw new AuthorizationException('La sede, área u horario indicados no pertenecen a la empresa activa.');
        }
    }
}
