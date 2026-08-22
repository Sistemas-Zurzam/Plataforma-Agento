<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaImportacion;
use App\Modules\Asistencia\Models\AsistenciaIncidencia;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AsistenciaOperacionService
{
    public function incidencias(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaIncidencia::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $estado === 'todos' ? $q : $q->where('estado', $estado))
            ->when($filtros['colaborador_id'] ?? null, fn ($q, $colaboradorId) => $q->where('colaborador_id', $colaboradorId))
            ->when($filtros['busqueda'] ?? null, function ($q, $busqueda) {
                $q->whereHas('colaborador', function ($colaborador) use ($busqueda) {
                    $colaborador->where(function ($campos) use ($busqueda) {
                        $campos->where('nombres', 'like', "%{$busqueda}%")
                            ->orWhere('apellidos', 'like', "%{$busqueda}%")
                            ->orWhere('legajo', 'like', "%{$busqueda}%")
                            ->orWhere('numero_documento', 'like', "%{$busqueda}%");
                    });
                });
            })
            ->when($filtros['sede'] ?? null, fn ($q, $sede) => $q->whereHas('colaborador.sede', fn ($s) => $s->where('nombre', $sede)))
            ->when($filtros['area'] ?? null, fn ($q, $area) => $q->whereHas('colaborador.area', fn ($a) => $a->where('nombre', $area)))
            ->with(['colaborador:id,nombres,apellidos,legajo,area_id', 'colaborador.area', 'resultado'])
            ->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 25);
    }

    public function horasExtra(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaHoraExtra::query()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$filtros['fecha_desde'], $filtros['fecha_hasta']])
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $estado === 'todos' ? $q : $q->where('estado', $estado))
            ->with('colaborador:id,nombres,apellidos,legajo,area_id')
            ->orderByDesc('fecha')->paginate($filtros['per_page'] ?? 25);
    }

    public function importaciones(Empresa $empresa, int $perPage): LengthAwarePaginator
    {
        return AsistenciaImportacion::query()->where('empresa_id', $empresa->id)
            ->latest()->paginate($perPage);
    }

    public function auditoria(Empresa $empresa, int $perPage, ?int $entidadId = null): LengthAwarePaginator
    {
        return AsistenciaAuditoria::query()->where('empresa_id', $empresa->id)
            ->when($entidadId, fn ($q) => $q->where('entidad_id', $entidadId))
            ->latest()->paginate($perPage);
    }
}
