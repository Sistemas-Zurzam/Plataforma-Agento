<?php

namespace App\Modules\Personas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'colaborador_id', 'solicitante_user_id', 'origen', 'fecha_cese_propuesta', 'motivo', 'estado',
    'fecha_cese_aprobada', 'motivo_cambio_fecha', 'motivo_rechazo', 'resuelto_por', 'resuelto_at',
    'fecha_cese_efectiva', 'ejecutada_por', 'ejecutada_at',
])]
class SolicitudCese extends Model
{
    public const ESTADO_ENVIADA = 'enviada';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_RECHAZADA = 'rechazada';

    protected $table = 'personas_solicitudes_cese';

    protected function casts(): array
    {
        return [
            'fecha_cese_propuesta' => 'date', 'fecha_cese_aprobada' => 'date', 'fecha_cese_efectiva' => 'date',
            'resuelto_at' => 'datetime', 'ejecutada_at' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_user_id');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function ejecutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutada_por');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(SolicitudCeseEvidencia::class, 'solicitud_cese_id');
    }

    /**
     * Etapa visible: distingue aprobación de ejecución real del cese.
     */
    public function etapa(): string
    {
        if ($this->ejecutada_at) {
            return 'ejecutada';
        }

        return $this->estado === self::ESTADO_APROBADA ? 'aprobada_pendiente_ejecucion' : $this->estado;
    }

    /** Una solicitud "abierta" bloquea crear otra para el mismo colaborador. */
    public function estaAbierta(): bool
    {
        return $this->estado === self::ESTADO_ENVIADA || ($this->estado === self::ESTADO_APROBADA && ! $this->ejecutada_at);
    }
}
