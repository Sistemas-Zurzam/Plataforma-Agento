<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['empresa_id', 'asignacion_id', 'iniciado_at', 'vence_at', 'finalizado_at', 'cierre', 'puntaje'])]
class PruebaIntento extends Model
{
    protected $table = 'seleccion_prueba_intentos';

    protected function casts(): array
    {
        return ['iniciado_at' => 'datetime', 'vence_at' => 'datetime', 'finalizado_at' => 'datetime', 'puntaje' => 'float'];
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
