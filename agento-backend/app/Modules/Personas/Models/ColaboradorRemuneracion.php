<?php

namespace App\Modules\Personas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['colaborador_id', 'salario', 'moneda_salario', 'periodicidad_pago', 'asignacion_familiar', 'vigencia_desde'])]
class ColaboradorRemuneracion extends Model
{
    /**
     * Eloquent pluralizaría "ColaboradorRemuneracion" como
     * "colaborador_remuneracions" (regla en inglés); la tabla real es
     * "colaborador_remuneraciones".
     */
    protected $table = 'colaborador_remuneraciones';

    protected function casts(): array
    {
        return [
            'salario' => 'decimal:2',
            'asignacion_familiar' => 'decimal:2',
            'vigencia_desde' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
