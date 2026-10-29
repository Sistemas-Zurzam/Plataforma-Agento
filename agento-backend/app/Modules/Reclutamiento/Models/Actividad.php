<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'entidad_tipo', 'entidad_id', 'accion', 'actor_user_id', 'actor_tipo', 'motivo', 'cambios'])]
class Actividad extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seleccion_actividades';

    protected function casts(): array
    {
        return ['cambios' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
