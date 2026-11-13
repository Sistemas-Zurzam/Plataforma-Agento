<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío de boleta de pago por correo (ver migración create_boleta_envios_table).
 * Sin scope global de empresa, igual que Boleta: el acceso se verifica en el
 * controller contra la empresa del ciclo.
 */
#[Fillable([
    'empresa_id', 'ciclo_id', 'colaborador_id', 'boleta_id', 'boleta_version', 'tipo',
    'email', 'estado', 'error', 'enviado_por', 'enviado_at',
])]
class BoletaEnvio extends Model
{
    public const TIPO_BOLETA = 'boleta';

    protected $table = 'boleta_envios';

    protected function casts(): array
    {
        return ['enviado_at' => 'datetime'];
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }
}
