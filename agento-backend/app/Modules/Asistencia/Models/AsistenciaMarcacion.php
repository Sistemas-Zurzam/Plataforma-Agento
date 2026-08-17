<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'colaborador_id', 'person_id', 'marcado_at', 'origen',
    'dispositivo', 'datos_origen', 'anulada_at', 'anulada_por',
])]
class AsistenciaMarcacion extends Model
{
    protected $table = 'asistencia_marcaciones';

    protected function casts(): array
    {
        return [
            'marcado_at' => 'datetime',
            'datos_origen' => 'array',
            'anulada_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function importaciones(): BelongsToMany
    {
        return $this->belongsToMany(
            AsistenciaImportacion::class,
            'asistencia_importacion_marcaciones',
            'marcacion_id',
            'importacion_id'
        )->withPivot('fue_nueva')->withTimestamps();
    }
}
