<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Database\Factories\NominaImportacionHistoricaDetalleCorreccionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoría (patrón "changelog") de una corrección manual sobre una fila de
 * staging — generada por `ImportarAntecedentesHistoricosService::corregir()`,
 * `confirmarCtsDepositada()`, `confirmarGratificacionPagada()` o
 * `marcarIgnorado()`. Nunca se crea ni se modifica desde ningún otro lugar.
 */
#[Fillable(['detalle_id', 'campo', 'valor_anterior', 'valor_nuevo', 'motivo', 'corregido_por', 'corregido_at'])]
class NominaImportacionHistoricaDetalleCorreccion extends Model
{
    /** @use HasFactory<NominaImportacionHistoricaDetalleCorreccionFactory> */
    use HasFactory;

    protected $table = 'nomina_importacion_historica_detalle_correcciones';

    protected static function newFactory(): NominaImportacionHistoricaDetalleCorreccionFactory
    {
        return NominaImportacionHistoricaDetalleCorreccionFactory::new();
    }

    protected function casts(): array
    {
        return ['corregido_at' => 'datetime'];
    }

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(NominaImportacionHistoricaDetalle::class, 'detalle_id');
    }

    public function corregidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corregido_por');
    }
}
