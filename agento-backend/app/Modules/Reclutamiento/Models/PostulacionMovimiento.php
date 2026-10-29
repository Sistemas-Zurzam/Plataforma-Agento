<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'empresa_id', 'postulacion_id', 'etapa_anterior', 'etapa_nueva', 'estado_anterior', 'estado_nuevo', 'motivo',
    'actor_user_id', 'actor_tipo',
])]
class PostulacionMovimiento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seleccion_postulacion_movimientos';

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
