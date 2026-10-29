<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cada evaluación conserva a su autor: varias personas pueden evaluar la
 * misma entrevista y ninguna sobrescribe a otra.
 */
#[Fillable([
    'empresa_id', 'entrevista_id', 'evaluador_user_id', 'fortalezas', 'riesgos', 'recomendacion', 'comentarios',
    'promedio', 'puntaje',
])]
class EntrevistaEvaluacion extends Model
{
    public const RECOMENDACIONES = ['avanzar', 'en_duda', 'no_avanzar'];

    protected $table = 'seleccion_entrevista_evaluaciones';

    protected function casts(): array
    {
        return ['promedio' => 'float', 'puntaje' => 'float'];
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_user_id');
    }

    public function calificaciones(): HasMany
    {
        return $this->hasMany(EntrevistaCalificacion::class, 'evaluacion_id');
    }
}
