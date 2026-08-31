<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'colaborador_id', 'fecha', 'tipo', 'dias', 'descripcion', 'registrado_por'])]
class VacacionMovimiento extends Model
{
    protected $table = 'vacacion_movimientos';
    protected function casts(): array { return ['fecha' => 'date', 'dias' => 'decimal:4']; }
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
}
