<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['solicitud_id', 'nombre', 'nivel'])]
class SolicitudHabilidad extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_solicitud_habilidades';

    protected function casts(): array
    {
        return ['nivel' => 'integer'];
    }
}
