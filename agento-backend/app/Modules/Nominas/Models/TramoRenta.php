<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['categoria', 'orden', 'limite_inferior_uit', 'limite_superior_uit', 'tasa_porcentaje', 'vigencia_desde'])]
class TramoRenta extends Model
{
    protected $table = 'tramos_renta';

    protected function casts(): array
    {
        return [
            'limite_inferior_uit' => 'decimal:2',
            'limite_superior_uit' => 'decimal:2',
            'tasa_porcentaje' => 'decimal:2',
            'vigencia_desde' => 'date',
        ];
    }
}
