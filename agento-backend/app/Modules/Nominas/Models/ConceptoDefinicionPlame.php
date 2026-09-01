<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clasificación PLAME concreta y reutilizable de un concepto motor
 * demasiado genérico (BONIFICACION, BONO_NO_REMUNERATIVO) — ver migración
 * 000080. El código PLAME es explícito y elegido por un administrador,
 * nunca asignado automáticamente.
 */
#[Fillable(['concepto_remuneracion_id', 'nombre', 'codigo_plame', 'descripcion_sunat', 'activo', 'creado_por'])]
class ConceptoDefinicionPlame extends Model
{
    protected $table = 'concepto_definiciones_plame';

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoRemuneracion::class, 'concepto_remuneracion_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
