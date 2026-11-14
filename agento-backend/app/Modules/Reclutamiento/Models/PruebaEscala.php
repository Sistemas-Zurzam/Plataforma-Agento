<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto de la escala de la prueba (ej. 1 = "Totalmente en desacuerdo").
 * Las preguntas de tipo "escala" generan sus opciones a partir de ella.
 */
#[Fillable(['prueba_id', 'valor', 'etiqueta', 'orden'])]
class PruebaEscala extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_escala';

    protected function casts(): array
    {
        return ['valor' => 'float', 'orden' => 'integer'];
    }
}
