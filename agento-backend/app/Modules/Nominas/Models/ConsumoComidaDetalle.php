<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un consumo (una fila del Excel). errores_formato guarda los errores de
 * lectura de la fila (no cambian); errores es el resultado completo de la
 * última validación, que se repite al confirmar.
 */
#[Fillable([
    'importacion_id', 'empresa_id', 'fila', 'documento', 'colaborador_id', 'colaborador_nombre_snapshot',
    'fecha_consumo', 'concepto', 'sistema_origen', 'referencia', 'referencia_activa', 'monto',
    'valores_originales', 'estado', 'errores', 'errores_formato',
    'duplicado_admitido_por', 'duplicado_admitido_at', 'duplicado_motivo',
])]
class ConsumoComidaDetalle extends Model
{
    public const ESTADO_VALIDO = 'valido';

    public const ESTADO_ERROR = 'error';

    public const ESTADO_POSIBLE_DUPLICADO = 'posible_duplicado';

    protected $table = 'consumo_comida_detalles';

    protected function casts(): array
    {
        return [
            'fecha_consumo' => 'date:Y-m-d',
            'monto' => 'decimal:2',
            'valores_originales' => 'array',
            'errores' => 'array',
            'errores_formato' => 'array',
            'duplicado_admitido_at' => 'datetime',
        ];
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ConsumoComidaImportacion::class, 'importacion_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }
}
