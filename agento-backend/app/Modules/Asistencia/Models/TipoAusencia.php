<?php

namespace App\Modules\Asistencia\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global (no tiene empresa_id, igual que conceptos_remuneracion):
 * "por qué un colaborador no trabajó" es una clasificación nacional, no una
 * decisión propia de cada empresa. codigo_sunat_suspension mapea a la
 * Tabla 21 del Anexo 3 de SUNAT — queda NULL hasta que se cargue ese
 * catálogo oficial, nunca un valor inventado.
 */
#[Fillable([
    'codigo', 'nombre', 'afecta_asistencia', 'remunerada', 'codigo_sunat_suspension', 'descripcion_sunat', 'activo',
    // sunat_* son un estado de clasificación independiente de `activo`
    // (que controla si el tipo está disponible en Asistencia) — ver
    // migración 000076.
    'sunat_no_aplica', 'sunat_bloqueado_por_modelo', 'sunat_motivo_estado',
])]
class TipoAusencia extends Model
{
    protected $table = 'tipos_ausencia';

    protected function casts(): array
    {
        return [
            'afecta_asistencia' => 'boolean',
            'remunerada' => 'boolean',
            'activo' => 'boolean',
            'sunat_no_aplica' => 'boolean',
            'sunat_bloqueado_por_modelo' => 'boolean',
        ];
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(AsistenciaPermiso::class);
    }
}
