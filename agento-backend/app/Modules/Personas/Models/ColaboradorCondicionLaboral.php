<?php

namespace App\Modules\Personas\Models;

use App\Modules\Configuracion\Models\Afp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'colaborador_id', 'regimen_laboral', 'tipo_contrato', 'categoria_trabajador', 'sistema_previsional', 'afp_id', 'tipo_comision', 'vigencia_desde',
])]
class ColaboradorCondicionLaboral extends Model
{
    protected $table = 'colaborador_condiciones_laborales';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function afp(): BelongsTo
    {
        return $this->belongsTo(Afp::class);
    }
}
