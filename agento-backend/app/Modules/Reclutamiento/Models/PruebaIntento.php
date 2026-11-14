<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id', 'asignacion_id', 'iniciado_at', 'vence_at', 'finalizado_at', 'cierre', 'puntaje',
    'conclusion', 'conclusion_comentario', 'concluido_por', 'concluido_at',
])]
class PruebaIntento extends Model
{
    protected $table = 'seleccion_prueba_intentos';

    protected function casts(): array
    {
        return ['iniciado_at' => 'datetime', 'vence_at' => 'datetime', 'finalizado_at' => 'datetime', 'puntaje' => 'float', 'concluido_at' => 'datetime'];
    }

    public const CONCLUSIONES = ['recomendada', 'recomendada_con_reservas', 'no_recomendada'];

    public function concluidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluido_por');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(PruebaAsignacion::class, 'asignacion_id');
    }

    public function respuestas(): HasMany
    {
        return $this->hasMany(IntentoRespuesta::class, 'intento_id');
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(IntentoResultado::class, 'intento_id');
    }
}
