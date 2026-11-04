<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Auditoría de una importación de consumos: validación, duplicados admitidos, aplicación y anulación. */
#[Fillable(['importacion_id', 'empresa_id', 'usuario_id', 'accion', 'motivo', 'datos'])]
class ConsumoComidaEvento extends Model
{
    protected $table = 'consumo_comida_eventos';

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
