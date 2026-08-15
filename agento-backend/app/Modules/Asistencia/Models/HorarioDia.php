<?php

namespace App\Modules\Asistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'horario_id',
    'dia_semana',
    'estado',
    'hora_entrada',
    'hora_salida',
    'refrigerio_inicio',
    'refrigerio_fin',
    'jornada_nocturna',
    'permitir_horas_extra',
])]
class HorarioDia extends Model
{
    protected function casts(): array
    {
        return [
            'jornada_nocturna' => 'boolean',
            'permitir_horas_extra' => 'boolean',
        ];
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class);
    }
}
