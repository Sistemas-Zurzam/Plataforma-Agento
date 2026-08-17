<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaSolicitudArea;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SolicitudAreaService
{
    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    public function listar(Empresa $empresa, User $usuario, ?string $estado, int $perPage): LengthAwarePaginator
    {
        $esRrhh = $usuario->currentRole()?->clave === 'administrador'
            || $usuario->currentRole()?->permissions->contains('clave', 'asistencia.aprobar_rrhh');
        return AsistenciaSolicitudArea::query()->where('empresa_id', $empresa->id)
            ->when(! $esRrhh, fn ($query) => $query->where('area_id', $usuario->area_id))
            ->when($estado && $estado !== 'todos', fn ($query) => $query->where('estado', $estado))
            ->with(['area', 'colaboradores.area'])->latest()->paginate($perPage);
    }

    public function crear(Empresa $empresa, array $datos, User $usuario): AsistenciaSolicitudArea
    {
        $esRrhh = $usuario->currentRole()?->clave === 'administrador'
            || $usuario->currentRole()?->permissions->contains('clave', 'asistencia.aprobar_rrhh');
        if (! $esRrhh && $datos['origen'] !== 'responsable_area') {
            throw ValidationException::withMessages(['origen' => ['El origen indicado no está autorizado para tu rol.']]);
        }
        $colaboradores = Colaborador::query()->where('empresa_id', $empresa->id)
            ->whereIn('id', $datos['colaborador_ids'])->get();
        if ($colaboradores->count() !== count(array_unique($datos['colaborador_ids']))) {
            throw ValidationException::withMessages(['colaborador_ids' => ['Uno o más colaboradores no pertenecen a la empresa activa.']]);
        }

        if (! $esRrhh && ($colaboradores->pluck('area_id')->unique()->count() !== 1 || (int) $colaboradores->first()?->area_id !== (int) $usuario->area_id)) {
            throw ValidationException::withMessages(['colaborador_ids' => ['Solo puedes gestionar colaboradores de tu área.']]);
        }

        $solicitud = DB::transaction(function () use ($empresa, $datos, $usuario, $colaboradores) {
            $solicitud = AsistenciaSolicitudArea::query()->create([
                ...collect($datos)->except('colaborador_ids')->all(),
                'empresa_id' => $empresa->id,
                'area_id' => $colaboradores->pluck('area_id')->unique()->count() === 1 ? $colaboradores->first()->area_id : null,
                'estado' => $datos['origen'] === 'rrhh_directo' ? 'pendiente_rrhh' : 'pendiente_responsable',
                'creado_por' => $usuario->id,
            ]);
            $solicitud->colaboradores()->sync($colaboradores->modelKeys());
            return $solicitud->load(['area', 'colaboradores.area']);
        });
        $this->auditoria->registrar($empresa->id, $usuario->id, 'solicitud_creada', $solicitud, $datos['motivo'], null, $solicitud->toArray());
        return $solicitud;
    }
}
