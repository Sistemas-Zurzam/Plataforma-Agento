<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['evaluacion_id', 'competencia', 'calificacion'])]
class EntrevistaCalificacion extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_entrevista_calificaciones';

    protected function casts(): array
    {
        return ['calificacion' => 'integer'];
    }
}
