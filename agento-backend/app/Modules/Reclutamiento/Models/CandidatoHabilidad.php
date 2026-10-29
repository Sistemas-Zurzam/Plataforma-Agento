<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['candidato_id', 'nombre', 'nivel'])]
class CandidatoHabilidad extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_candidato_habilidades';

    protected function casts(): array
    {
        return ['nivel' => 'integer'];
    }
}
