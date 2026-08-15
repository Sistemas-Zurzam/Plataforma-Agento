<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'afp_id',
    'vigencia_desde',
    'aporte_obligatorio_porcentaje',
    'prima_seguro_porcentaje',
    'comision_flujo_porcentaje',
    'comision_mixta_porcentaje',
    'sobre_saldo_anual_porcentaje',
])]
class ComisionAfp extends Model
{
    /**
     * Eloquent pluralizaría "ComisionAfp" como "comision_afps"
     * (regla en inglés); la tabla real es "comisiones_afp".
     */
    protected $table = 'comisiones_afp';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'aporte_obligatorio_porcentaje' => 'decimal:2',
            'prima_seguro_porcentaje' => 'decimal:2',
            'comision_flujo_porcentaje' => 'decimal:2',
            'comision_mixta_porcentaje' => 'decimal:2',
            'sobre_saldo_anual_porcentaje' => 'decimal:2',
        ];
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }
}
