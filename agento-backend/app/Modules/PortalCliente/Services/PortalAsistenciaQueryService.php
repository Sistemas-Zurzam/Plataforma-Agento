<?php

namespace App\Modules\PortalCliente\Services;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultas de solo lectura del Portal Cliente para asistencia. Ningún
 * método aquí escribe nada ni reutiliza servicios de escritura del panel
 * admin (AsistenciaDecisionService, AsistenciaPeriodoService, etc.) — solo
 * lee los mismos estados que esos servicios ya calculan.
 *
 * Todas las consultas filtran explícitamente por empresa_id además del
 * #[ScopedBy(EmpresaScope::class)] que ya traen los modelos de Asistencia
 * (mismo criterio "cinturón y tirantes" que el resto del código: nunca se
 * depende solo del scope global).
 */
class PortalAsistenciaQueryService
{
    /**
     * Las 4 métricas "jornadas_*" cuentan FILAS de AsistenciaResultadoDiario
     * (una por colaborador y día) dentro del rango — es decir, jornadas, no
     * colaboradores distintos. Un mismo colaborador con 5 días "presente"
     * en el rango suma 5 a jornadas_presentes, no 1. colaboradores_activos
     * es la única métrica de conteo de personas (el roster actual, sin
     * relación con el rango de fechas) — se mantiene deliberadamente
     * separada para no mezclar ambas unidades bajo un mismo nombre.
     *
     * @return array<string, int>
     */
    public function resumen(Empresa $empresa, string $fechaDesde, string $fechaHasta): array
    {
        return [
            'colaboradores_activos' => Colaborador::where('empresa_id', $empresa->id)->where('activo', true)->count(),
            'jornadas_presentes' => $this->contarResultados($empresa, null, $fechaDesde, $fechaHasta, 'presente'),
            'jornadas_con_falta' => $this->contarResultados($empresa, null, $fechaDesde, $fechaHasta, 'falta'),
            'jornadas_descanso' => $this->contarResultados($empresa, null, $fechaDesde, $fechaHasta, 'descanso'),
            'jornadas_marcacion_incompleta' => $this->contarResultados($empresa, null, $fechaDesde, $fechaHasta, 'marcacion_incompleta'),
            'incidencias_pendientes' => AsistenciaIncidencia::where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
            'horas_extra_pendientes' => AsistenciaHoraExtra::where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
            'permisos_registrados' => AsistenciaPermiso::where('empresa_id', $empresa->id)
                ->whereDate('fecha_inicio', '<=', $fechaHasta)->whereDate('fecha_fin', '>=', $fechaDesde)->count(),
        ];
    }

    private function contarResultados(Empresa $empresa, ?Colaborador $colaborador, string $desde, string $hasta, string $estado): int
    {
        return AsistenciaResultadoDiario::where('empresa_id', $empresa->id)
            ->when($colaborador, fn ($q) => $q->where('colaborador_id', $colaborador->id))
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', $estado)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listarColaboradores(Empresa $empresa, array $filtros, int $perPage): LengthAwarePaginator
    {
        return Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->with(['area', 'sede'])
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['sede_id'] ?? null, fn ($q, $sedeId) => $q->where('sede_id', $sedeId))
            ->when($filtros['estado'] ?? null, function ($q, $estado) {
                if ($estado === 'activo') {
                    $q->where('activo', true);
                } elseif ($estado === 'inactivo') {
                    $q->where('activo', false);
                }
            })
            ->when($filtros['busqueda'] ?? null, function ($q, $busqueda) {
                $q->where(function ($sub) use ($busqueda) {
                    $sub->where('nombres', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('legajo', 'like', "%{$busqueda}%")
                        ->orWhere('numero_documento', 'like', "%{$busqueda}%");
                });
            })
            ->orderBy('apellidos')->orderBy('nombres')
            ->paginate($perPage);
    }

    /**
     * Punto único de verificación: un colaborador de otra empresa nunca
     * pasa de acá, sin importar qué otra ruta llegó a resolverlo primero.
     */
    public function colaboradorDeEmpresaActiva(Empresa $empresa, Colaborador $colaborador): Colaborador
    {
        abort_unless($colaborador->empresa_id === $empresa->id, 404);

        return $colaborador;
    }

    /**
     * @return array{colaborador: Colaborador, metricas: array<string, int>}
     */
    public function perfilResumen(Empresa $empresa, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): array
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);
        $colaborador->loadMissing(['area', 'sede']);

        return [
            'colaborador' => $colaborador,
            'metricas' => [
                'jornadas_presentes' => $this->contarResultados($empresa, $colaborador, $fechaDesde, $fechaHasta, 'presente'),
                'jornadas_con_falta' => $this->contarResultados($empresa, $colaborador, $fechaDesde, $fechaHasta, 'falta'),
                'jornadas_descanso' => $this->contarResultados($empresa, $colaborador, $fechaDesde, $fechaHasta, 'descanso'),
                'jornadas_marcacion_incompleta' => $this->contarResultados($empresa, $colaborador, $fechaDesde, $fechaHasta, 'marcacion_incompleta'),
                'incidencias_pendientes' => AsistenciaIncidencia::where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
                'horas_extra_pendientes' => AsistenciaHoraExtra::where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
                'permisos_registrados' => AsistenciaPermiso::where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereDate('fecha_inicio', '<=', $fechaHasta)->whereDate('fecha_fin', '>=', $fechaDesde)->count(),
            ],
        ];
    }

    /**
     * Combina el plan declarado (ColaboradorCalendarioDia) con el resultado
     * real ya calculado (AsistenciaResultadoDiario) por día. Para días de
     * colaboradores rotativos sin declarar todavía, tipo_dia_planificado
     * queda en null a propósito: generar ese día virtual es responsabilidad
     * de CalendarioMensualGenerator (un servicio de escritura/generación),
     * que este incremento de solo lectura no reutiliza para no reimplementar
     * su lógica — se documenta como limitación conocida, no se inventa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calendario(Empresa $empresa, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): array
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        $planificado = ColaboradorCalendarioDia::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta])
            ->get()->keyBy(fn ($dia) => $dia->fecha->toDateString());

        $resultados = AsistenciaResultadoDiario::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta])
            ->with(['incidencias' => fn ($q) => $q->where('estado', 'pendiente')])
            ->get()->keyBy(fn ($resultado) => $resultado->fecha->toDateString());

        $dias = [];
        $cursor = Carbon::parse($fechaDesde);
        $fin = Carbon::parse($fechaHasta);

        while ($cursor->lte($fin)) {
            $fecha = $cursor->toDateString();
            $resultado = $resultados->get($fecha);

            $dias[] = [
                'fecha' => $fecha,
                'tipo_dia_planificado' => $planificado->get($fecha)?->tipo,
                'estado' => $resultado?->estado,
                'entrada' => $resultado?->entrada_at?->format('Y-m-d H:i:s'),
                'salida' => $resultado?->salida_at?->format('Y-m-d H:i:s'),
                'minutos_trabajados' => $resultado?->minutos_trabajados,
                'incidencias_pendientes' => $resultado?->incidencias->count() ?? 0,
            ];

            $cursor->addDay();
        }

        return $dias;
    }

    /**
     * No existe columna "tipo" (entrada/salida) en asistencia_marcaciones —
     * ese par se deriva a nivel de AsistenciaResultadoDiario durante el
     * procesamiento, no por marcación individual. Se documenta como
     * limitación real del modelo de datos, no se inventa el campo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function marcaciones(Empresa $empresa, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): Collection
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        return AsistenciaMarcacion::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('marcado_at', ["{$fechaDesde} 00:00:00", "{$fechaHasta} 23:59:59"])
            ->orderByDesc('marcado_at')
            ->get()
            ->map(fn ($marcacion) => [
                'id' => $marcacion->id,
                'marcado_at' => $marcacion->marcado_at?->format('Y-m-d H:i:s'),
                'origen' => $marcacion->origen,
                'estado' => $marcacion->anulada_at ? 'anulada' : 'valida',
            ]);
    }

    /**
     * "Historial" del portal es deliberadamente distinto del "Historial"
     * admin (que expone AsistenciaAuditoria, con usuario_id interno y
     * acciones de RR.HH.) — acá es la línea de tiempo de los propios
     * resultados diarios ya calculados del colaborador.
     */
    public function historial(Empresa $empresa, Colaborador $colaborador, string $fechaDesde, string $fechaHasta, int $perPage): LengthAwarePaginator
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        return AsistenciaResultadoDiario::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta])
            ->orderByDesc('fecha')
            ->paginate($perPage)
            ->through(fn ($resultado) => [
                'id' => $resultado->id,
                'fecha' => $resultado->fecha?->toDateString(),
                'tipo_dia' => $resultado->tipo_dia,
                'estado' => $resultado->estado,
                'entrada' => $resultado->entrada_at?->format('Y-m-d H:i:s'),
                'salida' => $resultado->salida_at?->format('Y-m-d H:i:s'),
                'minutos_trabajados' => $resultado->minutos_trabajados,
                'minutos_tardanza' => $resultado->minutos_tardanza,
            ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function incidenciasDeColaborador(Empresa $empresa, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        return AsistenciaIncidencia::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->with('colaborador.area')
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function horasExtraDeColaborador(Empresa $empresa, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        return AsistenciaHoraExtra::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->with('colaborador.area')
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function permisosDeColaborador(Empresa $empresa, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $this->colaboradorDeEmpresaActiva($empresa, $colaborador);

        return AsistenciaPermiso::where('empresa_id', $empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->with('colaborador.area')
            ->orderByDesc('fecha_inicio')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function incidencias(Empresa $empresa, array $filtros, int $perPage): LengthAwarePaginator
    {
        return AsistenciaIncidencia::where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $id) => $q->where('colaborador_id', $id))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas('colaborador', fn ($c) => $c->where('area_id', $areaId)))
            ->when($filtros['sede_id'] ?? null, fn ($q, $sedeId) => $q->whereHas('colaborador', fn ($c) => $c->where('sede_id', $sedeId)))
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $q->whereHas('colaborador', fn ($c) => $c
                ->where('nombres', 'like', "%{$busqueda}%")
                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                ->orWhere('legajo', 'like', "%{$busqueda}%")))
            ->with('colaborador.area')
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function horasExtra(Empresa $empresa, array $filtros, int $perPage): LengthAwarePaginator
    {
        return AsistenciaHoraExtra::where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $id) => $q->where('colaborador_id', $id))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas('colaborador', fn ($c) => $c->where('area_id', $areaId)))
            ->when($filtros['sede_id'] ?? null, fn ($q, $sedeId) => $q->whereHas('colaborador', fn ($c) => $c->where('sede_id', $sedeId)))
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $q->whereHas('colaborador', fn ($c) => $c
                ->where('nombres', 'like', "%{$busqueda}%")
                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                ->orWhere('legajo', 'like', "%{$busqueda}%")))
            ->with('colaborador.area')
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function permisos(Empresa $empresa, array $filtros, int $perPage): LengthAwarePaginator
    {
        return AsistenciaPermiso::where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $id) => $q->where('colaborador_id', $id))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas('colaborador', fn ($c) => $c->where('area_id', $areaId)))
            ->when($filtros['sede_id'] ?? null, fn ($q, $sedeId) => $q->whereHas('colaborador', fn ($c) => $c->where('sede_id', $sedeId)))
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $q->whereHas('colaborador', fn ($c) => $c
                ->where('nombres', 'like', "%{$busqueda}%")
                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                ->orWhere('legajo', 'like', "%{$busqueda}%")))
            ->with('colaborador.area')
            ->orderByDesc('fecha_inicio')
            ->paginate($perPage);
    }
}
