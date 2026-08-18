<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'area_id', 'tipo', 'origen', 'fecha_inicio', 'fecha_fin', 'motivo',
    'medio_recepcion', 'estado', 'creado_por', 'responsable_por', 'responsable_at',
    'observacion_responsable', 'rrhh_por', 'rrhh_at', 'observacion_rrhh',
])]
class AsistenciaSolicitudArea extends Model
{
    protected $table = 'asistencia_solicitudes_area';
    protected function casts(): array { return ['fecha_inicio' => 'date', 'fecha_fin' => 'date', 'responsable_at' => 'datetime', 'rrhh_at' => 'datetime']; }
    public function area(): BelongsTo { return $this->belongsTo(Area::class); }
    public function colaboradores(): BelongsToMany { return $this->belongsToMany(Colaborador::class, 'asistencia_solicitud_colaboradores', 'solicitud_id', 'colaborador_id')->withTrashed(); }
}
