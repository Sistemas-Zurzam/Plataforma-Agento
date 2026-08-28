<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Configuracion\Models\Banco;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot bancario 1:1 de una Boleta, congelado al cerrar el ciclo — ver
 * migración crear_boleta_datos_pago y CicloRemunerativoService::cerrar().
 * Nunca se recalcula ni se actualiza con la cuenta actual del colaborador.
 */
#[Fillable(['boleta_id', 'banco_id', 'tipo_cuenta_snapshot', 'moneda_snapshot', 'numero_cuenta_snapshot', 'cci_snapshot', 'fecha_snapshot'])]
class BoletaDatosPago extends Model
{
    protected $table = 'boleta_datos_pago';

    protected function casts(): array
    {
        return ['fecha_snapshot' => 'datetime'];
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }
}
