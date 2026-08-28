<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle 1:1 de una Boleta de honorarios (locador) — estructura E20/.4ta
 * de PLAME. Sin empresa_id propio: el acceso siempre pasa por la Boleta
 * padre, ya autorizada (mismo criterio que colaborador_remuneraciones).
 */
#[Fillable([
    'boleta_id', 'tipo_comprobante', 'serie', 'numero', 'fecha_emision', 'fecha_pago',
    'indicador_retencion_4ta', 'indicador_retencion_regimen_pensionario',
    'importe_aporte_regimen_pensionario', 'registrado_por',
])]
class BoletaComprobanteRh extends Model
{
    protected $table = 'boleta_comprobantes_rh';

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_pago' => 'date',
            'indicador_retencion_4ta' => 'boolean',
            'importe_aporte_regimen_pensionario' => 'decimal:2',
        ];
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
