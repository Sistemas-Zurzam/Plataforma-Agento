<?php

namespace App\Modules\Configuracion\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['empresa_id', 'definicion_id', 'regimen_laboral', 'vigencia_desde', 'valor'])]
class ParametroLaboralValor extends Model
{
    /**
     * Eloquent pluraliza con reglas en inglés: "valor" -> "valors"
     * (incorrecto en español, la tabla es "valores"). Se fija el nombre
     * explícitamente.
     */
    protected $table = 'parametro_laboral_valores';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
            'valor' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function definicion(): BelongsTo
    {
        return $this->belongsTo(ParametroLaboralDefinicion::class, 'definicion_id');
    }
}
