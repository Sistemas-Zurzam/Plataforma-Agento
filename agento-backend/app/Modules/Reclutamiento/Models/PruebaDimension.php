<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['prueba_id', 'nombre', 'peso'])]
class PruebaDimension extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_dimensiones';

    protected function casts(): array
    {
        return ['peso' => 'integer'];
    }
}
