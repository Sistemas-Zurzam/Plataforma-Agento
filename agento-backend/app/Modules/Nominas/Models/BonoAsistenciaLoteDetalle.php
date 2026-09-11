<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Database\Factories\BonoAsistenciaLoteDetalleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fila por colaborador dentro de un BonoAsistenciaLote — ver migración
 * 2026_09_08_000129_crear_bono_asistencia_lote_detalles.php para el porqué
 * de cada columna (snapshot propuesto vs. lo que Livex devuelve al
 * reimportar el Excel).
 */
#[Fillable([
    'bono_asistencia_lote_id', 'colaborador_id', 'documento_snapshot', 'colaborador_nombre_snapshot',
    'dias_falta_justificada', 'dias_falta_injustificada', 'tardanzas',
    'bono_base', 'porcentaje_propuesto', 'monto_propuesto',
    'dias_falta_injustificada_livex', 'dias_falta_justificada_livex',
    'meta_comercial_cumplida', 'aprobado', 'porcentaje_final', 'monto_final', 'observacion_livex',
    'colaborador_concepto_periodo_id', 'planilla_complementaria_detalle_id',
])]
class BonoAsistenciaLoteDetalle extends Model
{
    /** @use HasFactory<BonoAsistenciaLoteDetalleFactory> */
    use HasFactory;

    protected $table = 'bono_asistencia_lote_detalles';

    protected function casts(): array
    {
        return [
            'bono_base' => 'decimal:2',
            'monto_propuesto' => 'decimal:2',
            'monto_final' => 'decimal:2',
            'meta_comercial_cumplida' => 'boolean',
            'aprobado' => 'boolean',
        ];
    }

    protected static function newFactory(): BonoAsistenciaLoteDetalleFactory
    {
        return BonoAsistenciaLoteDetalleFactory::new();
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(BonoAsistenciaLote::class, 'bono_asistencia_lote_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function conceptoPeriodo(): BelongsTo
    {
        return $this->belongsTo(ColaboradorConceptoPeriodo::class, 'colaborador_concepto_periodo_id');
    }

    /**
     * La línea dentro de la complementaria dedicada (ver
     * BonoAsistenciaLote::planillaComplementaria()) que le correspondió a
     * este colaborador — solo cuando su boleta ya estaba pagada al aplicar.
     */
    public function planillaComplementariaDetalle(): BelongsTo
    {
        return $this->belongsTo(PlanillaComplementariaDetalle::class, 'planilla_complementaria_detalle_id');
    }
}
