<?php

namespace App\Modules\Reclutamiento\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Area;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Configuracion\Models\Scopes\EmpresaScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy([EmpresaScope::class])]
#[Fillable([
    'empresa_id', 'solicitud_id', 'codigo', 'area_id', 'reclutador_user_id', 'cargo', 'descripcion', 'modalidad',
    'tipo_contrato', 'ubicacion', 'salario_min', 'salario_max', 'moneda', 'mostrar_salario', 'empresa_confidencial',
    'fecha_requerida', 'fecha_inicio', 'fecha_cierre_postulacion', 'estado', 'motivo_estado', 'mensaje_publico',
    'publicacion_interna', 'publicacion_externa', 'publicada_interna_at', 'publicada_externa_at', 'retirada_at',
    'cubierto_at',
])]
class ProcesoSeleccion extends Model
{
    public const BORRADOR = 'borrador';

    public const ABIERTO = 'abierto';

    public const EN_PAUSA = 'en_pausa';

    public const CUBIERTO = 'cubierto';

    public const CERRADO = 'cerrado';

    public const CANCELADO = 'cancelado';

    protected $table = 'seleccion_procesos';

    protected function casts(): array
    {
        return [
            'fecha_requerida' => 'date',
            'fecha_inicio' => 'date',
            'fecha_cierre_postulacion' => 'date',
            'salario_min' => 'decimal:2',
            'salario_max' => 'decimal:2',
            'mostrar_salario' => 'boolean',
            'empresa_confidencial' => 'boolean',
            'publicacion_interna' => 'boolean',
            'publicacion_externa' => 'boolean',
            'publicada_interna_at' => 'datetime',
            'publicada_externa_at' => 'datetime',
            'retirada_at' => 'datetime',
            'cubierto_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudPersonal::class, 'solicitud_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function reclutador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reclutador_user_id');
    }

    public function perfiles(): HasMany
    {
        return $this->hasMany(PerfilProceso::class, 'proceso_id')->orderByDesc('version');
    }

    public function perfilVigente(): HasOne
    {
        return $this->hasOne(PerfilProceso::class, 'proceso_id')->ofMany('version', 'max');
    }

    public function plazas(): HasMany
    {
        return $this->hasMany(Plaza::class, 'proceso_id')->orderBy('numero');
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class, 'proceso_id');
    }

    /**
     * Visible en el portal público: publicación externa vigente y proceso
     * abierto o en pausa (en pausa se muestra con su mensaje público, pero
     * no admite postulaciones). Una publicación solo interna nunca aparece.
     */
    public function scopePublicoVisible(Builder $query): Builder
    {
        return $query->where('publicacion_externa', true)
            ->whereNull('retirada_at')
            ->whereIn('estado', [self::ABIERTO, self::EN_PAUSA]);
    }

    public function aceptaPostulaciones(): bool
    {
        return $this->estado === self::ABIERTO
            && $this->retirada_at === null
            && ($this->publicacion_externa || $this->publicacion_interna)
            && ($this->fecha_cierre_postulacion === null || $this->fecha_cierre_postulacion->copy()->endOfDay()->isFuture());
    }
}
