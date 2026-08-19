<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['beneficio_social_id', 'colaborador_id', 'sueldo_basico', 'meses', 'bruta', 'bonificacion_extraordinaria', 'neta'])]
class BeneficioSocialDetalle extends Model
{
    protected $table = 'beneficio_social_detalles';

    protected function casts(): array
    {
        return [
            'sueldo_basico' => 'decimal:2',
            'bruta' => 'decimal:2',
            'bonificacion_extraordinaria' => 'decimal:2',
            'neta' => 'decimal:2',
        ];
    }

    public function beneficioSocial(): BelongsTo
    {
        return $this->belongsTo(BeneficioSocial::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
