<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un envío de boleta de CTS por correo (ver migración create_beneficio_social_envios_table). */
#[Fillable(['empresa_id', 'beneficio_social_detalle_id', 'colaborador_id', 'email', 'estado', 'error', 'enviado_por', 'enviado_at'])]
class BeneficioSocialEnvio extends Model
{
    protected $table = 'beneficio_social_envios';

    protected function casts(): array
    {
        return ['enviado_at' => 'datetime'];
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(BeneficioSocialDetalle::class, 'beneficio_social_detalle_id');
    }
}
