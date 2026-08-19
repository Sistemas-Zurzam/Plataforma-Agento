<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'codigo', 'nombre', 'tipo', 'es_remunerativo_laboral', 'afecta_renta_5ta',
    'afecta_afp', 'afecta_essalud', 'afecta_cts', 'afecta_gratificacion', 'afecta_vacaciones',
    'codigo_plame', 'codigo_afpnet', 'alerta_recurrencia_meses', 'activo',
])]
class ConceptoRemuneracion extends Model
{
    protected $table = 'conceptos_remuneracion';

    protected function casts(): array
    {
        return [
            'es_remunerativo_laboral' => 'boolean',
            'afecta_renta_5ta' => 'boolean',
            'afecta_afp' => 'boolean',
            'afecta_essalud' => 'boolean',
            'afecta_cts' => 'boolean',
            'afecta_gratificacion' => 'boolean',
            'afecta_vacaciones' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}
