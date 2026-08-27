<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'tipo', 'anio', 'version', 'es_version_vigente',
    'total_colaboradores', 'total_bruto', 'total_neto', 'estado',
    'calculado_por', 'calculado_at', 'pagado_por', 'pagado_at', 'referencia_pago',
])]
class BeneficioSocial extends Model
{
    protected $table = 'beneficios_sociales';

    protected function casts(): array
    {
        return [
            'es_version_vigente' => 'boolean',
            'total_bruto' => 'decimal:2',
            'total_neto' => 'decimal:2',
            'calculado_at' => 'datetime',
            'pagado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(BeneficioSocialDetalle::class);
    }
}
