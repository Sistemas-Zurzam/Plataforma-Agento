<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'boleta_id', 'concepto_id', 'concepto_definicion_id', 'tipo', 'es_remunerativo_laboral', 'afecta_renta_5ta',
    'codigo_plame_snapshot', 'base_utilizada', 'tasa_aplicada', 'cantidad', 'monto',
    'monto_devengado', 'monto_pagado_descontado', 'formula_texto',
])]
class BoletaConcepto extends Model
{
    protected $table = 'boleta_conceptos';

    protected function casts(): array
    {
        return [
            'es_remunerativo_laboral' => 'boolean',
            'afecta_renta_5ta' => 'boolean',
            'base_utilizada' => 'decimal:2',
            'tasa_aplicada' => 'decimal:4',
            'cantidad' => 'decimal:2',
            'monto' => 'decimal:2',
            'monto_devengado' => 'decimal:2',
            'monto_pagado_descontado' => 'decimal:2',
        ];
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoRemuneracion::class, 'concepto_id');
    }

    public function conceptoDefinicion(): BelongsTo
    {
        return $this->belongsTo(ConceptoDefinicionPlame::class, 'concepto_definicion_id');
    }
}
