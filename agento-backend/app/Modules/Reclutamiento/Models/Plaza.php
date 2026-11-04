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
    'empresa_id', 'proceso_id', 'numero', 'estado', 'postulacion_id', 'colaborador_id', 'cubierta_at',
    'cancelada_at', 'motivo_cancelacion',
])]
class Plaza extends Model
{
    public const DISPONIBLE = 'disponible';

    public const CUBIERTA = 'cubierta';

    public const CANCELADA = 'cancelada';

    protected $table = 'seleccion_plazas';

    protected function casts(): array
    {
        return ['cubierta_at' => 'datetime', 'cancelada_at' => 'datetime', 'numero' => 'integer'];
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(ProcesoSeleccion::class, 'proceso_id');
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
