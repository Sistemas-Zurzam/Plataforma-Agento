<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sin #[ScopedBy(EmpresaScope::class)] a propósito: el selector de "Planilla
 * mensual" lista y permite operar sobre ciclos de CUALQUIER empresa
 * autorizada del usuario, no solo la empresa activa (ver
 * CicloRemunerativoController) — un scope global filtraría eso al vuelo a
 * una sola empresa. La verificación de acceso vive en el controller
 * (User::tieneAccesoA) antes de cada operación.
 */
#[Fillable([
    'empresa_id', 'nombre', 'periodicidad', 'fecha_inicio', 'fecha_fin', 'fecha_corte_asistencia', 'fecha_pago', 'estado', 'creado_por',
    'calculo_estado', 'calculo_iniciado_at', 'calculo_finalizado_at', 'calculo_resultado',
])]
class CicloRemunerativo extends Model
{
    protected $table = 'ciclos_remunerativos';

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_corte_asistencia' => 'date',
            'fecha_pago' => 'date',
            'calculo_iniciado_at' => 'datetime',
            'calculo_finalizado_at' => 'datetime',
            'calculo_resultado' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function boletas(): HasMany
    {
        return $this->hasMany(Boleta::class, 'ciclo_id');
    }
}
