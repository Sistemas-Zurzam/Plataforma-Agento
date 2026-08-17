<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorHorarioAsignacion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'empresa_id',
    'nombre',
    'tolerancia_minutos',
    'tipo_turno',
    'descripcion',
    'cruza_medianoche',
    'vigencia_desde',
    'vigencia_hasta',
    'activo',
])]
class Horario extends Model
{
    protected function casts(): array
    {
        return [
            'cruza_medianoche' => 'boolean',
            'activo' => 'boolean',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function dias(): HasMany
    {
        return $this->hasMany(HorarioDia::class)->orderBy('dia_semana');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(ColaboradorHorarioAsignacion::class);
    }
}
