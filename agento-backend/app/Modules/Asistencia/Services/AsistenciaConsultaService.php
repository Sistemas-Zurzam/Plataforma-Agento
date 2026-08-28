<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AsistenciaConsultaService
{
    public function obtenerColaborador(Empresa $empresa, Colaborador $colaborador, array $filtros): Colaborador
    {
        abort_unless($colaborador->empresa_id === $empresa->id, 404);

        $colaborador->load([
            'empresa', 'sede', 'area', 'horario',
            'asignacionesHorario' => fn ($query) => $query
                ->whereDate('vigencia_desde', '<=', $filtros['fecha_hasta'])
                ->where(fn ($vigencia) => $vigencia->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $filtros['fecha_desde']))
                ->with('horario'),
            'calendario' => fn ($query) => $query->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']]),
            'resultadosAsistencia' => fn ($query) => $query->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']]),
            // V3 Fase 3 — A4: el perfil único necesita poder abrir "Editar
            // marcaciones" para una incidencia sin una segunda consulta;
            // reutiliza el mismo resultado ya cargado por la pestaña Calendario.
            'incidenciasAsistencia' => fn ($query) => $query->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])->orderByDesc('fecha')->with('resultado'),
            'marcacionesAsistencia' => fn ($query) => $query->whereBetween('marcado_at', [
                $filtros['fecha_desde'].' 00:00:00', $filtros['fecha_hasta'].' 23:59:59',
            ])->latest('marcado_at'),
        ]);
        $colaborador->setRelation('permisosAsistencia', AsistenciaPermiso::query()
            ->where('empresa_id', $empresa->id)->where('colaborador_id', $colaborador->id)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->orderByDesc('fecha_inicio')->get());
        $colaborador->setRelation('horasExtraAsistencia', AsistenciaHoraExtra::query()
            ->where('empresa_id', $empresa->id)->where('colaborador_id', $colaborador->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])->orderByDesc('fecha')->get());
        $colaborador->setRelation('auditoriaAsistencia', AsistenciaAuditoria::query()
            ->where('empresa_id', $empresa->id)->where('colaborador_id', $colaborador->id)
            ->whereBetween('created_at', [$filtros['fecha_desde'].' 00:00:00', $filtros['fecha_hasta'].' 23:59:59'])
            ->latest()->get());

        return $colaborador;
    }

    public function listarColaboradores(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        $desde = $filtros['fecha_desde'];
        $hasta = $filtros['fecha_hasta'];

        return Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->with([
                'empresa', 'sede', 'area', 'horario',
                'marcacionesAsistencia' => fn ($query) => $query->latest('marcado_at'),
                'asignacionesHorario' => fn ($query) => $query
                    ->whereDate('vigencia_desde', '<=', $hasta)
                    ->where(fn ($vigencia) => $vigencia->whereNull('vigencia_hasta')->orWhereDate('vigencia_hasta', '>=', $desde))
                    ->with('horario'),
                'calendario' => fn ($query) => $query->whereBetween('fecha', [$desde, $hasta]),
                'resultadosAsistencia' => fn ($query) => $query->whereBetween('fecha', [$desde, $hasta]),
                'incidenciasAsistencia' => fn ($query) => $query->whereBetween('fecha', [$desde, $hasta])->where('estado', 'pendiente'),
            ])
            ->when($filtros['area_id'] ?? null, fn ($query, $areaId) => $query->where('area_id', $areaId))
            ->when($filtros['sede'] ?? null, fn ($query, $sede) => $query->whereHas('sede', fn ($q) => $q->where('nombre', $sede)))
            ->when($filtros['area'] ?? null, fn ($query, $area) => $query->whereHas('area', fn ($q) => $q->where('nombre', $area)))
            ->when($filtros['preparacion'] ?? null, function ($query, $preparacion) use ($desde, $hasta) {
                // V3 P3 — un trabajador de confianza sin horario_id no es una
                // preparación incompleta, es la condición esperada.
                if ($preparacion === 'sin_horario') $query->whereNull('horario_id')->where('es_trabajador_confianza', false);
                if ($preparacion === 'sin_calendario') $query->whereDoesntHave('calendario', fn ($q) => $q->whereBetween('fecha', [$desde, $hasta]));
                if ($preparacion === 'listos') $query
                    ->where(fn ($q) => $q->whereNotNull('horario_id')->orWhere('es_trabajador_confianza', true))
                    ->whereHas('calendario', fn ($q) => $q->whereBetween('fecha', [$desde, $hasta]));
            })
            ->when($filtros['estado'] ?? null, function ($query, $estado) {
                if ($estado === 'activo') {
                    $query->where('activo', true);
                } elseif ($estado === 'inactivo') {
                    $query->where('activo', false);
                }
            })
            ->when($filtros['busqueda'] ?? null, function ($query, $busqueda) {
                $query->where(function ($campos) use ($busqueda) {
                    $campos->where('nombres', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('numero_documento', 'like', "%{$busqueda}%")
                        ->orWhere('legajo', 'like', "%{$busqueda}%");
                });
            })
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->paginate($filtros['per_page'] ?? 25);
    }

    public function estadisticasColaboradores(Empresa $empresa, array $filtros): array
    {
        $base = Colaborador::query()->where('empresa_id', $empresa->id);
        $total = (clone $base)->count();
        // V3 P3 — un trabajador de confianza sin horario_id no es una
        // preparación incompleta, se cuenta como "con horario" igual.
        $conHorario = (clone $base)->where(fn ($q) => $q->whereNotNull('horario_id')->orWhere('es_trabajador_confianza', true))->count();
        $conCalendario = (clone $base)->whereHas('calendario', fn ($query) => $query
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']]))->count();

        return [
            'total' => $total,
            'listos' => (clone $base)
                ->where(fn ($q) => $q->whereNotNull('horario_id')->orWhere('es_trabajador_confianza', true))
                ->whereHas('calendario', fn ($query) => $query
                ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']]))->count(),
            'falta_horario' => max(0, $total - $conHorario),
            'falta_calendario' => max(0, $total - $conCalendario),
            'incidencias' => AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->whereHas('incidencias', fn ($query) => $query->where('estado', 'pendiente'))->count(),
            'marcaciones_incompletas' => AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->where('estado', 'marcacion_incompleta')->count(),
            'horas_extra_pendientes' => AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->where('minutos_extra_observados', '>', 0)
                ->count(),
        ];
    }

    public function listar(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->with(['colaborador.area', 'incidencias'])
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['area_id'] ?? null, function ($query, $areaId) {
                $query->whereHas('colaborador', fn ($colaborador) => $colaborador->where('area_id', $areaId));
            })
            ->when($filtros['busqueda'] ?? null, function ($query, $busqueda) {
                $query->whereHas('colaborador', function ($colaborador) use ($busqueda) {
                    $colaborador->where(function ($campos) use ($busqueda) {
                        $campos->where('nombres', 'like', "%{$busqueda}%")
                            ->orWhere('apellidos', 'like', "%{$busqueda}%")
                            ->orWhere('legajo', 'like', "%{$busqueda}%")
                            ->orWhere('numero_documento', 'like', "%{$busqueda}%");
                    });
                });
            })
            ->orderByDesc('fecha')
            ->orderBy('colaborador_id')
            ->paginate($filtros['per_page'] ?? 25);
    }

    public function estadisticas(Empresa $empresa, array $filtros): array
    {
        $conteos = AsistenciaResultadoDiario::query()
            ->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'jornadas' => $conteos->sum(),
            'presentes' => (int) ($conteos['presente'] ?? 0),
            'tardanzas' => AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)
                ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->where('minutos_tardanza', '>', 0)->count(),
            'faltas' => (int) ($conteos['falta'] ?? 0),
            'incompletas' => (int) ($conteos['marcacion_incompleta'] ?? 0),
            'home_office' => (int) ($conteos['home_office'] ?? 0),
            'incidencias_pendientes' => \App\Modules\Asistencia\Models\AsistenciaIncidencia::query()
                ->where('empresa_id', $empresa->id)->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->where('estado', 'pendiente')->count(),
            'minutos_extra_observados' => AsistenciaResultadoDiario::query()
                ->where('empresa_id', $empresa->id)->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
                ->sum('minutos_extra_observados'),
        ];
    }
}
