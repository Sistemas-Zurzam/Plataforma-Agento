<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ciclo_id', 'empresa_id', 'colaborador_id', 'version', 'es_version_vigente',
    'regimen_laboral_snapshot', 'sueldo_basico_snapshot', 'dias_pagados',
    'asistencia_procesada', 'dias_falta', 'minutos_tardanza',
    'total_ingresos', 'total_egresos', 'total_aportaciones', 'neto_a_pagar', 'estado',
    'snapshot_parametros_version', 'snapshot_reglas_version', 'alertas', 'motivo_recalculo',
    'calculado_por', 'calculado_at', 'aprobado_por', 'aprobado_at', 'pagado_por', 'pagado_at', 'referencia_pago',
])]
class Boleta extends Model
{
    protected $table = 'boletas';

    protected function casts(): array
    {
        return [
            'es_version_vigente' => 'boolean',
            'sueldo_basico_snapshot' => 'decimal:2',
            'dias_pagados' => 'decimal:2',
            'asistencia_procesada' => 'boolean',
            'dias_falta' => 'decimal:2',
            'total_ingresos' => 'decimal:2',
            'total_egresos' => 'decimal:2',
            'total_aportaciones' => 'decimal:2',
            'neto_a_pagar' => 'decimal:2',
            'alertas' => 'array',
            'calculado_at' => 'datetime',
            'aprobado_at' => 'datetime',
            'pagado_at' => 'datetime',
        ];
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(BoletaConcepto::class);
    }
}
