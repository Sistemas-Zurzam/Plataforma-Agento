<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['empresa_id', 'nombre', 'periodicidad', 'fecha_inicio', 'fecha_fin', 'fecha_corte_asistencia', 'fecha_pago', 'estado', 'creado_por'])]
class CicloRemunerativo extends Model
{
    protected $table = 'ciclos_remunerativos';

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_corte_asistencia' => 'date',
            'fecha_pago' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class, 'ciclo_id');
    }
}
