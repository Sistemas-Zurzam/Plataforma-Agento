<?php

namespace App\Modules\Nominas\Models;

use App\Modules\Personas\Models\Colaborador;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Efecto de una importación sobre un colaborador: el descuento (suma de sus
 * consumos) escrito como ColaboradorConceptoPeriodo. boleta_id / boleta_retiro_id
 * registran qué versión de boleta incorporó el descuento y cuál lo retiró
 * después de anularse; mientras falten, el descuento está pendiente de
 * recálculo.
 */
#[Fillable([
    'importacion_id', 'empresa_id', 'ciclo_id', 'colaborador_id', 'monto', 'consumos',
    'colaborador_concepto_periodo_id', 'estado', 'aplicado_at', 'anulado_at', 'boleta_id', 'boleta_retiro_id',
])]
class ConsumoComidaAplicacion extends Model
{
    public const ESTADO_APLICADA = 'aplicada';

    public const ESTADO_ANULADA = 'anulada';

    protected $table = 'consumo_comida_aplicaciones';

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'aplicado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    /** Aplicadas todavía no incorporadas, o anuladas cuyo retiro no se recalculó. */
    public function scopePendientesDeRecalculo(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $aplicada) => $aplicada->where('estado', self::ESTADO_APLICADA)->whereNull('boleta_id'))
            ->orWhere(fn (Builder $anulada) => $anulada->where('estado', self::ESTADO_ANULADA)
                ->whereNotNull('boleta_id')->whereNull('boleta_retiro_id')));
    }

    /**
     * La boleta recién calculada leyó los conceptos del período vigentes al
     * inicio del cálculo: incorpora las aplicaciones hechas hasta ese
     * momento y registra el retiro de las anuladas que ya estaban en una
     * boleta. Nunca vuelve a sumar consumos: el monto vive solo en el
     * ColaboradorConceptoPeriodo.
     */
    public static function registrarIncorporacion(int $cicloId, int $colaboradorId, int $boletaId, CarbonInterface $inicioCalculo): void
    {
        $base = fn () => static::query()->where('ciclo_id', $cicloId)->where('colaborador_id', $colaboradorId);

        $base()->where('estado', self::ESTADO_APLICADA)->whereNull('boleta_id')
            ->where('aplicado_at', '<=', $inicioCalculo)
            ->update(['boleta_id' => $boletaId]);
        $base()->where('estado', self::ESTADO_ANULADA)->whereNotNull('boleta_id')->whereNull('boleta_retiro_id')
            ->where('anulado_at', '<=', $inicioCalculo)
            ->update(['boleta_retiro_id' => $boletaId]);
    }

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ConsumoComidaImportacion::class, 'importacion_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function boleta(): BelongsTo
    {
        return $this->belongsTo(Boleta::class);
    }

    public function boletaRetiro(): BelongsTo
    {
        return $this->belongsTo(Boleta::class, 'boleta_retiro_id');
    }
}
