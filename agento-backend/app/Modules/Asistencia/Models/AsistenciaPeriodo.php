<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'fecha_inicio', 'fecha_fin', 'estado', 'cerrado_at',
    'cerrado_por', 'reabierto_at', 'reabierto_por', 'enviado_nomina_at',
    'enviado_nomina_por', 'snapshot_nomina', 'version',
    'cobertura_estado', 'cobertura_iniciado_at', 'cobertura_finalizado_at', 'cobertura_resultado',
])]
class AsistenciaPeriodo extends Model
{
    protected $table = 'asistencia_periodos';

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'cerrado_at' => 'datetime',
            'reabierto_at' => 'datetime',
            'enviado_nomina_at' => 'datetime',
            'snapshot_nomina' => 'array',
            'cobertura_iniciado_at' => 'datetime',
            'cobertura_finalizado_at' => 'datetime',
            'cobertura_resultado' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function resultados(): HasMany
    {
        return $this->hasMany(AsistenciaResultadoDiario::class, 'periodo_id');
    }
}
