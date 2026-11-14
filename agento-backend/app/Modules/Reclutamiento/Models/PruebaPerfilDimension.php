<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['perfil_id', 'codigo'])]
class PruebaPerfilDimension extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_perfil_dimensiones';
}
