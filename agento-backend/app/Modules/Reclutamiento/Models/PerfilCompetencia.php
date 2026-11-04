<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['perfil_id', 'nombre', 'nivel', 'peso'])]
class PerfilCompetencia extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_perfil_competencias';

    protected function casts(): array
    {
        return ['nivel' => 'integer', 'peso' => 'integer'];
    }
}
