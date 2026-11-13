<?php

namespace App\Modules\Auditoria\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bitácora inmutable: una vez escrito, un evento no se edita ni se elimina
 * desde la aplicación.
 */
#[ScopedBy(EmpresaScope::class)]
class AuditoriaEvento extends Model
{
    public const UPDATED_AT = null;

    public const TIPO_REQUEST = 'request';

    public const TIPO_UI = 'ui';

    public const TIPO_AUTH = 'auth';

    protected $table = 'auditoria_eventos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'cambios' => 'array',
            'status_code' => 'integer',
            'duracion_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los eventos de auditoría son inmutables.'));
        static::deleting(fn () => throw new LogicException('Los eventos de auditoría son inmutables.'));
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
