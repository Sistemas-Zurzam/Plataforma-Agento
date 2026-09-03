<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['planilla_complementaria_id', 'boleta_original_id', 'colaborador_id', 'banco_id', 'tipo_cuenta_snapshot', 'moneda_snapshot', 'numero_cuenta_snapshot', 'cci_snapshot', 'neto_original', 'neto_recalculado', 'diferencia_ingresos', 'diferencia_egresos', 'diferencia_aportaciones', 'diferencia_neta', 'calculo_snapshot'])]
class PlanillaComplementariaDetalle extends Model
{
    protected $table = 'planilla_complementaria_detalles';

    protected function casts(): array
    {
        return [
            'neto_original' => 'decimal:2', 'neto_recalculado' => 'decimal:2',
            'diferencia_ingresos' => 'decimal:2', 'diferencia_egresos' => 'decimal:2',
            'diferencia_aportaciones' => 'decimal:2', 'diferencia_neta' => 'decimal:2',
            'calculo_snapshot' => 'array',
        ];
    }

    public function complementaria(): BelongsTo { return $this->belongsTo(PlanillaComplementaria::class, 'planilla_complementaria_id'); }
    public function boletaOriginal(): BelongsTo { return $this->belongsTo(Boleta::class, 'boleta_original_id'); }
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
}
