<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['intento_id', 'pregunta_id', 'opcion_id', 'respondida_at'])]
class IntentoRespuesta extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_intento_respuestas';

    protected function casts(): array
    {
        return ['respondida_at' => 'datetime'];
    }
}
