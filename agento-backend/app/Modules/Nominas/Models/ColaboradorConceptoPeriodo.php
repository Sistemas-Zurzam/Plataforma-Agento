<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sin #[ScopedBy(EmpresaScope::class)] a propósito: siempre se consulta
 * acotado por ciclo_id (que puede pertenecer a cualquier empresa autorizada
 * del usuario, ver CicloRemunerativo), no por la empresa activa.
 */
#[Fillable(['empresa_id', 'ciclo_id', 'colaborador_id', 'concepto_id', 'concepto_definicion_id', 'monto', 'motivo', 'creado_por'])]
class ColaboradorConceptoPeriodo extends Model
{
    protected $table = 'colaborador_conceptos_periodo';

    protected function casts(): array
    {
        return ['monto' => 'decimal:2'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoRemuneracion::class, 'concepto_id');
    }

    public function conceptoDefinicion(): BelongsTo
    {
        return $this->belongsTo(ConceptoDefinicionPlame::class, 'concepto_definicion_id');
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id');
    }
}
