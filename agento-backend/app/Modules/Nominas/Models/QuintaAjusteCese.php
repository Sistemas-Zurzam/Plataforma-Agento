<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'colaborador_id', 'fecha_cese', 'anio', 'documento_asignado', 'estado', 'otros_empleadores_gravado', 'otros_empleadores_retenido', 'aplicaciones'])]
class QuintaAjusteCese extends Model
{
    public const LIQUIDACION = 'liquidacion';
    public const BOLETA = 'boleta';

    protected $table = 'quinta_ajustes_cese';

    protected function casts(): array
    {
        return [
            'fecha_cese' => 'date', 'aplicaciones' => 'array',
            'otros_empleadores_gravado' => 'decimal:2', 'otros_empleadores_retenido' => 'decimal:2',
        ];
    }

    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
}
