<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'postulacion_id', 'tipo', 'programada_at', 'lugar', 'evaluador_user_id', 'estado', 'creado_por'])]
class Entrevista extends Model
{
    public const TIPOS = ['virtual', 'presencial', 'gerencia'];

    public const ESTADOS = ['programada', 'realizada', 'cancelada'];

    protected $table = 'seleccion_entrevistas';

    protected function casts(): array
    {
        return ['programada_at' => 'datetime'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_user_id');
    }

    public function evaluaciones(): HasMany
    {
        return $this->hasMany(EntrevistaEvaluacion::class, 'entrevista_id')->orderBy('id');
    }
}
