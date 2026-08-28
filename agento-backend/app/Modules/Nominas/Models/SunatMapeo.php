<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equivalencia "valor interno fijo de Agento" (tipo + clave_interna) →
 * "código oficial SUNAT". Global, sin empresa_id: los códigos oficiales de
 * SUNAT no varían por empresa. Ver migración 000071 para el catálogo de
 * `tipo` soportados y su justificación.
 */
#[Fillable(['tipo', 'clave_interna', 'codigo_sunat', 'descripcion_sunat', 'activo', 'bloqueado_por_modelo', 'motivo_estado', 'actualizado_por_id'])]
class SunatMapeo extends Model
{
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'bloqueado_por_modelo' => 'boolean',
        ];
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_id');
    }
}
