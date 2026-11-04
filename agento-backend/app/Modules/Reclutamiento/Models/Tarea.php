<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'tipo', 'titulo', 'detalle', 'permiso_responsable', 'postulacion_id', 'colaborador_id', 'estado',
    'completada_por', 'completada_at',
])]
class Tarea extends Model
{
    public const COMPLETAR_FICHA = 'completar_ficha';

    public const ONBOARDING = 'onboarding';

    public const REINGRESO = 'reingreso';

    protected $table = 'seleccion_tareas';

    protected function casts(): array
    {
        return ['completada_at' => 'datetime'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }
}
