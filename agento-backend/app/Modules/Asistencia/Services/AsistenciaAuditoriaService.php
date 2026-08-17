<?php

namespace App\Modules\Asistencia\Services;

use App\Modules\Asistencia\Models\AsistenciaAuditoria;
use Illuminate\Database\Eloquent\Model;

class AsistenciaAuditoriaService
{
    public function registrar(int $empresaId, ?int $usuarioId, string $accion, Model|string $entidad, ?string $motivo = null, ?array $antes = null, ?array $despues = null): void
    {
        AsistenciaAuditoria::query()->create([
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'colaborador_id' => $entidad instanceof Model ? $entidad->getAttribute('colaborador_id') : null,
            'entidad_tipo' => $entidad instanceof Model ? $entidad::class : $entidad,
            'entidad_id' => $entidad instanceof Model ? $entidad->getKey() : null,
            'accion' => $accion,
            'motivo' => $motivo,
            'antes' => $antes,
            'despues' => $despues,
        ]);
    }
}
