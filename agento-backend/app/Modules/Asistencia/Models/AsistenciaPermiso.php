<?php

namespace App\Modules\Asistencia\Models;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable(['empresa_id', 'colaborador_id', 'tipo', 'tipo_ausencia_id', 'con_goce', 'pagador_subsidio', 'fecha_inicio', 'fecha_fin', 'motivo', 'estado', 'registrado_por', 'resuelto_por', 'resuelto_at', 'observacion_resolucion'])]
class AsistenciaPermiso extends Model
{
    protected $table = 'asistencia_permisos';

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date', 'resuelto_at' => 'datetime', 'con_goce' => 'boolean'];
    }

    public function empresa(): BelongsTo { return $this->belongsTo(Empresa::class); }
    public function colaborador(): BelongsTo { return $this->belongsTo(Colaborador::class)->withTrashed(); }
    public function tipoAusencia(): BelongsTo { return $this->belongsTo(TipoAusencia::class); }
}
