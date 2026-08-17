<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'importado_por', 'archivo_nombre', 'archivo_hash', 'estado',
    'fecha_minima', 'fecha_maxima', 'filas_totales', 'filas_nuevas',
    'filas_duplicadas', 'filas_observadas', 'errores',
])]
class AsistenciaImportacion extends Model
{
    protected $table = 'asistencia_importaciones';

    protected function casts(): array
    {
        return [
            'fecha_minima' => 'date',
            'fecha_maxima' => 'date',
            'errores' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function marcaciones(): BelongsToMany
    {
        return $this->belongsToMany(
            AsistenciaMarcacion::class,
            'asistencia_importacion_marcaciones',
            'importacion_id',
            'marcacion_id'
        )->withPivot('fue_nueva')->withTimestamps();
    }
}
