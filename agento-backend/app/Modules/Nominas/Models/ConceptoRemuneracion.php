<?php

namespace App\Modules\Nominas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'codigo', 'nombre', 'tipo', 'es_remunerativo_laboral', 'afecta_renta_5ta',
    'afecta_afp', 'afecta_essalud', 'afecta_cts', 'afecta_gratificacion', 'afecta_vacaciones',
    'codigo_plame', 'codigo_afpnet', 'alerta_recurrencia_meses', 'activo',
    // sunat_* son un estado de clasificación independiente de `activo`
    // (que controla si el concepto está disponible en Nóminas) — ver
    // migración 000076.
    'sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado',
])]
class ConceptoRemuneracion extends Model
{
    protected $table = 'conceptos_remuneracion';

    protected function casts(): array
    {
        return [
            'es_remunerativo_laboral' => 'boolean',
            'afecta_renta_5ta' => 'boolean',
            'afecta_afp' => 'boolean',
            'afecta_essalud' => 'boolean',
            'afecta_cts' => 'boolean',
            'afecta_gratificacion' => 'boolean',
            'afecta_vacaciones' => 'boolean',
            'activo' => 'boolean',
            'sunat_no_aplica' => 'boolean',
            'sunat_bloqueado_por_modelo' => 'boolean',
        ];
    }

    public function codigosPlameHistorial(): HasMany
    {
        return $this->hasMany(ConceptoRemuneracionCodigoPlame::class)->orderByDesc('vigencia_desde')->orderByDesc('id');
    }
}
