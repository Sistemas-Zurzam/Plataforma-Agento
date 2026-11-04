<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['intento_id', 'dimension_id', 'puntaje'])]
class IntentoResultado extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_intento_resultados';

    protected function casts(): array
    {
        return ['puntaje' => 'float'];
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(PruebaDimension::class, 'dimension_id');
    }
}
