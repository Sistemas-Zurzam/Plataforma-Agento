<?php

namespace App\Modules\Configuracion\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'minutos_desde', 'minutos_hasta', 'tipo', 'valor', 'orden'])]
class ReglaDescuentoTardanza extends Model
{
    protected $table = 'reglas_descuento_tardanza';

    protected function casts(): array
    {
        return [
            'minutos_desde' => 'integer',
            'minutos_hasta' => 'integer',
            'valor' => 'decimal:2',
            'orden' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
