<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['liquidacion_cese_id', 'codigo', 'nombre', 'tipo', 'monto', 'base_utilizada', 'cantidad', 'tasa_aplicada', 'formula_texto'])]
class LiquidacionCeseConcepto extends Model
{
    protected $table = 'liquidacion_cese_conceptos';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'base_utilizada' => 'decimal:2', 'cantidad' => 'decimal:4', 'tasa_aplicada' => 'decimal:6'];
    }

    public function liquidacion(): BelongsTo { return $this->belongsTo(LiquidacionCese::class, 'liquidacion_cese_id'); }
}
