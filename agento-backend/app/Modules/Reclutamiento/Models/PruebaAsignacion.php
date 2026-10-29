<?php

namespace App\Modules\Reclutamiento\Models;

use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([EmpresaScope::class])]
#[Hidden(['token_hash'])]
#[Fillable(['empresa_id', 'postulacion_id', 'prueba_id', 'token_hash', 'disponible_hasta', 'estado', 'asignado_por'])]
class PruebaAsignacion extends Model
{
    public const PENDIENTE = 'pendiente';

    public const EN_CURSO = 'en_curso';

    public const FINALIZADA = 'finalizada';

    public const VENCIDA = 'vencida';

    public const ANULADA = 'anulada';

    protected $table = 'seleccion_prueba_asignaciones';

    protected function casts(): array
    {
        return ['disponible_hasta' => 'datetime'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class, 'postulacion_id');
    }

    public function prueba(): BelongsTo
    {
        return $this->belongsTo(Prueba::class, 'prueba_id');
    }

    public function intento(): HasOne
    {
        return $this->hasOne(PruebaIntento::class, 'asignacion_id');
    }
}
