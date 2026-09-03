<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ciclo_id', 'empresa_id', 'nombre', 'motivo', 'estado', 'creado_por', 'aprobado_por', 'aprobado_at', 'pagado_por', 'pagado_at', 'referencia_pago'])]
class PlanillaComplementaria extends Model
{
    protected $table = 'planillas_complementarias';

    protected function casts(): array
    {
        return ['aprobado_at' => 'datetime', 'pagado_at' => 'datetime'];
    }

    public function ciclo(): BelongsTo { return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id'); }
    public function empresa(): BelongsTo { return $this->belongsTo(Empresa::class); }
    public function detalles(): HasMany { return $this->hasMany(PlanillaComplementariaDetalle::class); }
}
