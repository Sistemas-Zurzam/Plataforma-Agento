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
#[Fillable([
    'empresa_id', 'resultado_diario_id', 'colaborador_id', 'fecha', 'tipo',
    'estado', 'descripcion', 'motivo_resolucion', 'resuelto_por', 'resuelto_at',
])]
class AsistenciaIncidencia extends Model
{
    protected $table = 'asistencia_incidencias';

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'resuelto_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(AsistenciaResultadoDiario::class, 'resultado_diario_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
