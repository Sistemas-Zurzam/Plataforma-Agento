<?php

namespace App\Modules\PortalCliente\Services;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCalendarioDia;
use App\Modules\PortalCliente\Application\AlcancePortal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consultas de solo lectura del Portal para asistencia. Ningún método aquí
 * escribe nada ni reutiliza servicios de escritura del panel admin
 * (AsistenciaDecisionService, AsistenciaPeriodoService, etc.) — solo lee
 * los mismos estados que esos servicios ya calculan.
 *
 * Todas las consultas pasan por AlcancePortal: empresa activa del JWT y,
 * para GER, solo colaboradores activos de sus áreas autorizadas. Los
 * contadores usan exactamente el mismo alcance que los listados.
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
    public function resumen(AlcancePortal $alcance, string $fechaDesde, string $fechaHasta): array
    {
        return [
            'colaboradores_activos' => $alcance->colaboradores()->where('colaboradores.activo', true)->count(),
            'jornadas_presentes' => $this->contarResultados($alcance, null, $fechaDesde, $fechaHasta, 'presente'),
            'jornadas_con_falta' => $this->contarResultados($alcance, null, $fechaDesde, $fechaHasta, 'falta'),
            'jornadas_descanso' => $this->contarResultados($alcance, null, $fechaDesde, $fechaHasta, 'descanso'),
            'jornadas_marcacion_incompleta' => $this->contarResultados($alcance, null, $fechaDesde, $fechaHasta, 'marcacion_incompleta'),
            'incidencias_pendientes' => $alcance->restringir(AsistenciaIncidencia::query(), 'asistencia_incidencias')
                ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
            'horas_extra_pendientes' => $alcance->restringir(AsistenciaHoraExtra::query(), 'asistencia_horas_extra')
                ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
            'permisos_registrados' => $alcance->restringir(AsistenciaPermiso::query(), 'asistencia_permisos')
                ->whereDate('fecha_inicio', '<=', $fechaHasta)->whereDate('fecha_fin', '>=', $fechaDesde)->count(),
        ];
    }

    private function contarResultados(AlcancePortal $alcance, ?Colaborador $colaborador, string $desde, string $hasta, string $estado): int
    {
        return $alcance->restringir(AsistenciaResultadoDiario::query(), 'asistencia_resultados_diarios')
            ->when($colaborador, fn ($q) => $q->where('colaborador_id', $colaborador->id))
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', $estado)
            ->count();
    }

    /**
     * Búsqueda por colaborador agrupada en un solo paréntesis: sin el
     * agrupamiento, un orWhere dentro de whereHas rompe la correlación con
     * la tabla externa (Colaborador no tiene EmpresaScope).
     */
    private function buscarColaborador(Builder $query, string $busqueda, bool $incluirDocumento = false): void
    {
        $query->where(function ($sub) use ($busqueda, $incluirDocumento) {
            $sub->where('nombres', 'like', "%{$busqueda}%")
                ->orWhere('apellidos', 'like', "%{$busqueda}%")
                ->orWhere('legajo', 'like', "%{$busqueda}%")
                ->when($incluirDocumento, fn ($q) => $q->orWhere('numero_documento', 'like', "%{$busqueda}%"));
        });
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listarColaboradores(AlcancePortal $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $alcance->colaboradores()
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
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $this->buscarColaborador($q, $busqueda, true))
            ->orderBy('apellidos')->orderBy('nombres')
            ->paginate($perPage);
    }

    /**
     * Grilla mensual del equipo: colaboradores (paginados) en filas y días
     * del mes en columnas. Solo datos operativos; un día sin resultado se
     * devuelve como null ("sin procesar"), nunca como falta.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{mes: string, dias: array<int, string>, colaboradores: LengthAwarePaginator}
     */
    public function equipoMensual(AlcancePortal $alcance, string $mes, array $filtros, int $perPage): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', "{$mes}-01")->startOfDay();
        $fin = $inicio->copy()->endOfMonth();
        $desde = $inicio->toDateString();
        $hasta = $fin->toDateString();

        $paginado = $alcance->colaboradores()
            ->with([
                'area:id,nombre',
                'resultadosAsistencia' => fn ($q) => $q->whereBetween('fecha', [$desde, $hasta]),
            ])
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $this->buscarColaborador($q, $busqueda))
            ->orderBy('apellidos')->orderBy('nombres')
            ->paginate($perPage);

        $incidenciasPendientes = $alcance->restringir(AsistenciaIncidencia::query(), 'asistencia_incidencias')
            ->whereIn('colaborador_id', $paginado->getCollection()->pluck('id'))
            ->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', 'pendiente')
            ->get(['colaborador_id', 'fecha'])
            ->groupBy(fn ($incidencia) => $incidencia->colaborador_id.'|'.$incidencia->fecha->toDateString())
            ->map->count();

        $paginado->through(function (Colaborador $colaborador) use ($incidenciasPendientes) {
            $resultados = [];
            foreach ($colaborador->resultadosAsistencia as $resultado) {
                $fecha = $resultado->fecha->toDateString();
                $resultados[$fecha] = [
                    'estado' => $resultado->estado,
                    'tipo_dia' => $resultado->tipo_dia,
                    'entrada' => $resultado->entrada_at?->format('Y-m-d H:i:s'),
                    'salida' => $resultado->salida_at?->format('Y-m-d H:i:s'),
                    'minutos_trabajados' => $resultado->minutos_trabajados,
                    'minutos_tardanza' => $resultado->minutos_tardanza,
                    'incidencias_pendientes' => $incidenciasPendientes->get($colaborador->id.'|'.$fecha, 0),
                ];
            }

            return [
                'id' => $colaborador->id,
                'legajo' => $colaborador->legajo,
                'nombre_completo' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
                'area' => $colaborador->area?->nombre,
                'cargo' => $colaborador->cargo,
                'resultados' => (object) $resultados,
            ];
        });

        $dias = [];
        for ($cursor = $inicio->copy(); $cursor->lte($fin); $cursor->addDay()) {
            $dias[] = $cursor->toDateString();
        }

        return ['mes' => $mes, 'dias' => $dias, 'colaboradores' => $paginado];
    }

    /**
     * Punto único de verificación: un colaborador fuera del alcance nunca
     * pasa de acá, sin importar qué otra ruta llegó a resolverlo primero.
     */
    public function colaboradorDeEmpresaActiva(AlcancePortal $alcance, Colaborador $colaborador): Colaborador
    {
        return $alcance->asegurarColaborador($colaborador);
    }

    /**
     * @return array{colaborador: Colaborador, metricas: array<string, int>}
     */
    public function perfilResumen(AlcancePortal $alcance, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): array
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);
        $colaborador->loadMissing(['area', 'sede']);
        $empresaId = $alcance->empresa->id;

        return [
            'colaborador' => $colaborador,
            'metricas' => [
                'jornadas_presentes' => $this->contarResultados($alcance, $colaborador, $fechaDesde, $fechaHasta, 'presente'),
                'jornadas_con_falta' => $this->contarResultados($alcance, $colaborador, $fechaDesde, $fechaHasta, 'falta'),
                'jornadas_descanso' => $this->contarResultados($alcance, $colaborador, $fechaDesde, $fechaHasta, 'descanso'),
                'jornadas_marcacion_incompleta' => $this->contarResultados($alcance, $colaborador, $fechaDesde, $fechaHasta, 'marcacion_incompleta'),
                'incidencias_pendientes' => AsistenciaIncidencia::where('empresa_id', $empresaId)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
                'horas_extra_pendientes' => AsistenciaHoraExtra::where('empresa_id', $empresaId)
                    ->where('colaborador_id', $colaborador->id)
                    ->whereBetween('fecha', [$fechaDesde, $fechaHasta])->where('estado', 'pendiente')->count(),
                'permisos_registrados' => AsistenciaPermiso::where('empresa_id', $empresaId)
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
    public function calendario(AlcancePortal $alcance, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): array
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        $planificado = ColaboradorCalendarioDia::where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$fechaDesde, $fechaHasta])
            ->get()->keyBy(fn ($dia) => $dia->fecha->toDateString());

        $resultados = AsistenciaResultadoDiario::where('empresa_id', $alcance->empresa->id)
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
    public function marcaciones(AlcancePortal $alcance, Colaborador $colaborador, string $fechaDesde, string $fechaHasta): Collection
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        return AsistenciaMarcacion::where('empresa_id', $alcance->empresa->id)
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
    public function historial(AlcancePortal $alcance, Colaborador $colaborador, string $fechaDesde, string $fechaHasta, int $perPage): LengthAwarePaginator
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        return AsistenciaResultadoDiario::where('empresa_id', $alcance->empresa->id)
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
    public function incidenciasDeColaborador(AlcancePortal $alcance, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        return AsistenciaIncidencia::where('empresa_id', $alcance->empresa->id)
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
    public function horasExtraDeColaborador(AlcancePortal $alcance, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        return AsistenciaHoraExtra::where('empresa_id', $alcance->empresa->id)
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
    public function permisosDeColaborador(AlcancePortal $alcance, Colaborador $colaborador, array $filtros, int $perPage): LengthAwarePaginator
    {
        $colaborador = $this->colaboradorDeEmpresaActiva($alcance, $colaborador);

        return AsistenciaPermiso::where('empresa_id', $alcance->empresa->id)
            ->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->with('colaborador.area')
            ->orderByDesc('fecha_inicio')
            ->paginate($perPage);
    }

    /**
     * Filtros comunes de los listados generales (toda el alcance).
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filtros
     */
    private function filtrosGenerales(Builder $query, array $filtros): Builder
    {
        return $query
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $id) => $q->where('colaborador_id', $id))
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas('colaborador', fn ($c) => $c->where('area_id', $areaId)))
            ->when($filtros['sede_id'] ?? null, fn ($q, $sedeId) => $q->whereHas('colaborador', fn ($c) => $c->where('sede_id', $sedeId)))
            ->when($filtros['busqueda'] ?? null, fn ($q, $busqueda) => $q->whereHas(
                'colaborador',
                fn ($c) => $this->buscarColaborador($c, $busqueda),
            ));
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function incidencias(AlcancePortal $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $this->filtrosGenerales($alcance->restringir(AsistenciaIncidencia::query(), 'asistencia_incidencias'), $filtros)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->with('colaborador.area')
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function horasExtra(AlcancePortal $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $this->filtrosGenerales($alcance->restringir(AsistenciaHoraExtra::query(), 'asistencia_horas_extra'), $filtros)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->with(['colaborador.area', 'gerenciaResueltoPor:id,name', 'resueltoPor:id,name'])
            ->orderByDesc('fecha')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function permisos(AlcancePortal $alcance, array $filtros, int $perPage): LengthAwarePaginator
    {
        return $this->filtrosGenerales($alcance->restringir(AsistenciaPermiso::query(), 'asistencia_permisos'), $filtros)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->with(['colaborador.area', 'resueltoPor:id,name'])
            ->orderByDesc('fecha_inicio')
            ->paginate($perPage);
    }
}
