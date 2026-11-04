<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada cálculo ATS es una fila nueva (historial): guarda versión del perfil,
 * fórmula, proveedor y desglose; nunca se sobrescribe.
 */
#[Fillable([
    'empresa_id', 'postulacion_id', 'perfil_version', 'puntaje', 'resultado', 'desglose', 'formula', 'proveedor',
    'origen', 'evaluado_por',
])]
class EvaluacionAts extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'seleccion_evaluaciones_ats';

    protected function casts(): array
    {
        return ['desglose' => 'array', 'puntaje' => 'float', 'perfil_version' => 'integer'];
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluado_por');
    }
}
