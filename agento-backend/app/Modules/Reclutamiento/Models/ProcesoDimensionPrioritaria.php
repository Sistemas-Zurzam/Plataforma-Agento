<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Dimensión de la prueba psicométrica que el proceso exige con el nivel
 * mínimo configurado (por código: se mantiene entre versiones de la prueba).
 */
#[Fillable(['proceso_id', 'codigo'])]
class ProcesoDimensionPrioritaria extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_proceso_dimensiones';
}
