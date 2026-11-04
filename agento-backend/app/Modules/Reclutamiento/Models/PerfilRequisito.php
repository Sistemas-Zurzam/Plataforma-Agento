<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['perfil_id', 'tipo', 'descripcion', 'orden'])]
class PerfilRequisito extends Model
{
    public const EXCLUYENTE = 'excluyente';

    public const DESEABLE = 'deseable';

    public $timestamps = false;

    protected $table = 'seleccion_perfil_requisitos';
}
