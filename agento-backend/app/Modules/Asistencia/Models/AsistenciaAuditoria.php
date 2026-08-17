<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'usuario_id', 'colaborador_id', 'entidad_tipo', 'entidad_id', 'accion', 'motivo', 'antes', 'despues'])]
class AsistenciaAuditoria extends Model
{
    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }
}
