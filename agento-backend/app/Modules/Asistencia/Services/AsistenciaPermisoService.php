<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Asistencia\Models\TipoAusencia;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AsistenciaPermisoService
{
    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    public function listar(Empresa $empresa, array $filtros): LengthAwarePaginator
    {
        return AsistenciaPermiso::query()
            ->where('empresa_id', $empresa->id)
            ->whereDate('fecha_inicio', '<=', $filtros['fecha_hasta'])
            ->whereDate('fecha_fin', '>=', $filtros['fecha_desde'])
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->with('colaborador.area')
            ->orderByDesc('fecha_inicio')
            ->paginate($filtros['per_page'] ?? 25);
    }

    public function crear(Empresa $empresa, array $datos, ?int $usuarioId): AsistenciaPermiso
    {
        $colaborador = Colaborador::query()
            ->where('empresa_id', $empresa->id)
            ->find($datos['colaborador_id']);

        if (! $colaborador) {
            throw (new ModelNotFoundException)->setModel(Colaborador::class, [$datos['colaborador_id']]);
        }

        $tipoAusencia = TipoAusencia::where('codigo', $datos['tipo'])->first();

        $permiso = AsistenciaPermiso::query()->create([
            ...$datos,
            'empresa_id' => $empresa->id,
            'estado' => 'pendiente',
            'registrado_por' => $usuarioId,
            // Resuelto por código exacto contra el catálogo — nunca se
            // adivina ni se inventa si no hay coincidencia (queda null, el
            // consolidado de PLAME lo trataría como "sin clasificar").
            'tipo_ausencia_id' => $tipoAusencia?->id,
            // Si el tipo tiene un goce FIJO (remunerada no es NULL en el
            // catálogo), se deriva automáticamente — nunca se le pregunta a
            // RR.HH. algo que ya está determinado (ej. vacaciones siempre
            // con goce, falta injustificada siempre sin goce). Solo para
            // tipos que varían (personal/capacitacion) se usa lo que envió
            // el formulario.
            'con_goce' => $tipoAusencia?->remunerada ?? $datos['con_goce'] ?? null,
        ])->load('colaborador.area');
        $this->auditoria->registrar($empresa->id, $usuarioId, 'permiso_creado', $permiso, $datos['motivo'], null, $permiso->toArray());
        return $permiso;
    }
}
