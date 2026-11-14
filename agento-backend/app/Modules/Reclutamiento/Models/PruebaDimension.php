<?php

namespace App\Modules\Reclutamiento\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'prueba_id', 'codigo', 'nombre', 'peso', 'es_validez', 'nivel_medio_desde', 'nivel_alto_desde', 'alerta_desde',
    'interpretacion_alta', 'interpretacion_baja', 'pregunta_entrevista',
])]
class PruebaDimension extends Model
{
    public $timestamps = false;

    protected $table = 'seleccion_prueba_dimensiones';

    protected function casts(): array
    {
        return [
            'peso' => 'integer',
            'es_validez' => 'boolean',
            'nivel_medio_desde' => 'float',
            'nivel_alto_desde' => 'float',
            'alerta_desde' => 'float',
        ];
    }
}
