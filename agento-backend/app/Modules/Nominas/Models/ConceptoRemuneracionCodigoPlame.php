<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['concepto_remuneracion_id', 'codigo_plame', 'descripcion_sunat', 'vigencia_desde', 'actualizado_por_id'])]
class ConceptoRemuneracionCodigoPlame extends Model
{
    /**
     * Nombre de tabla acortado ("concepto_codigos_plame") — ver comentario
     * en la migración 000073: con el nombre largo por defecto, MySQL
     * rechazaba el nombre autogenerado de la foreign key (>64 caracteres).
     */
    protected $table = 'concepto_codigos_plame';

    protected function casts(): array
    {
        return [
            'vigencia_desde' => 'date',
        ];
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoRemuneracion::class, 'concepto_remuneracion_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por_id');
    }
}
