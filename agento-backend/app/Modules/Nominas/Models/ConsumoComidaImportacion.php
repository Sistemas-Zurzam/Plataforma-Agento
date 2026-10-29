<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Importación de consumos de comida de un ciclo remunerativo. Sin
 * #[ScopedBy(EmpresaScope::class)] a propósito, igual que CicloRemunerativo:
 * se opera sobre el ciclo de cualquier empresa autorizada del usuario y el
 * acceso se verifica en el controller (User::tieneAccesoA) y en el servicio.
 *
 * Estados: validada (sin errores, lista para aplicar), con_errores, aplicada,
 * anulada. La ruta del archivo nunca se expone.
 */
#[Fillable([
    'empresa_id', 'ciclo_id', 'sistema_origen', 'archivo_nombre_original', 'archivo_ruta',
    'archivo_hash', 'contenido_hash', 'estado', 'errores_generales',
    'filas_totales', 'filas_con_error', 'filas_posible_duplicado', 'colaboradores_total', 'monto_total',
    'reemplaza_importacion_id', 'importado_por', 'aplicado_por', 'aplicado_at',
    'anulado_por', 'anulado_at', 'motivo_anulacion',
])]
#[Hidden(['archivo_ruta'])]
class ConsumoComidaImportacion extends Model
{
    public const ESTADO_VALIDADA = 'validada';

    public const ESTADO_CON_ERRORES = 'con_errores';

    public const ESTADO_APLICADA = 'aplicada';

    public const ESTADO_ANULADA = 'anulada';

    protected $table = 'consumo_comida_importaciones';

    protected function casts(): array
    {
        return [
            'errores_generales' => 'array',
            'monto_total' => 'decimal:2',
            'aplicado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloRemunerativo::class, 'ciclo_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ConsumoComidaDetalle::class, 'importacion_id');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(ConsumoComidaAplicacion::class, 'importacion_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ConsumoComidaEvento::class, 'importacion_id');
    }

    public function importadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importado_por');
    }

    public function aplicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
