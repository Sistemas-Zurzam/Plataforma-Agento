<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'area_id', 'solicitante_user_id', 'cargo', 'motivo', 'plazas', 'salario_min', 'salario_max',
    'moneda', 'tipo_contrato', 'modalidad', 'fecha_requerida', 'descripcion', 'actividades', 'justificacion',
    'presupuesto', 'urgente', 'estado', 'enviada_at', 'decision_motivo', 'decidida_por', 'decidida_at',
    'cancelacion_motivo', 'cancelada_por', 'cancelada_at',
])]
class SolicitudPersonal extends Model
{
    public const BORRADOR = 'borrador';

    public const ENVIADA = 'enviada';

    public const DEVUELTA = 'devuelta';

    public const ACEPTADA = 'aceptada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    public const MOTIVOS = ['reemplazo', 'nuevo_puesto', 'temporal'];

    public const TIPOS_CONTRATO = ['plazo_fijo', 'indefinido', 'locacion_servicios', 'practicas'];

    public const MODALIDADES = ['presencial', 'remoto', 'hibrido'];

    protected $table = 'seleccion_solicitudes';

    protected function casts(): array
    {
        return [
            'fecha_requerida' => 'date',
            'salario_min' => 'decimal:2',
            'salario_max' => 'decimal:2',
            'presupuesto' => 'decimal:2',
            'urgente' => 'boolean',
            'plazas' => 'integer',
            'enviada_at' => 'datetime',
            'decidida_at' => 'datetime',
            'cancelada_at' => 'datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_user_id');
    }

    public function habilidades(): HasMany
    {
        return $this->hasMany(SolicitudHabilidad::class, 'solicitud_id');
    }

    public function proceso(): HasOne
    {
        return $this->hasOne(ProcesoSeleccion::class, 'solicitud_id');
    }

    public function editable(): bool
    {
        return in_array($this->estado, [self::BORRADOR, self::DEVUELTA], true);
    }
}
