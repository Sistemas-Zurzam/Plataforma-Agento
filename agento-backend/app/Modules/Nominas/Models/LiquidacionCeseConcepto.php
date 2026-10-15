<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['liquidacion_cese_id', 'codigo', 'nombre', 'tipo', 'origen', 'monto', 'monto_calculado', 'base_utilizada', 'cantidad', 'tasa_aplicada', 'formula_texto', 'motivo_ajuste'])]
class LiquidacionCeseConcepto extends Model
{
    /** Propuesto por el sistema sin cambios. */
    public const ORIGEN_CALCULADO = 'calculado';
    /** Propuesto por el sistema y modificado por RR.HH. (conserva monto_calculado y motivo). */
    public const ORIGEN_AJUSTADO = 'ajustado';
    /** Ingresado a mano por RR.HH. */
    public const ORIGEN_MANUAL = 'manual';

    protected $table = 'liquidacion_cese_conceptos';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'monto_calculado' => 'decimal:2', 'base_utilizada' => 'decimal:2', 'cantidad' => 'decimal:4', 'tasa_aplicada' => 'decimal:6'];
    }

    public function liquidacion(): BelongsTo { return $this->belongsTo(LiquidacionCese::class, 'liquidacion_cese_id'); }
}
