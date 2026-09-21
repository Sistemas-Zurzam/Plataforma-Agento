<?php

namespace App\Modules\Nominas\Models;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Database\Factories\BeneficioSocialHistoricoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gratificación o CTS pagada/depositada FUERA de Agento (antes de agosto de
 * 2026), aprobada por RR.HH. tras conciliar el Excel histórico. No deriva
 * de `boleta_conceptos` ni reemplaza a `BeneficioSocial`/`BeneficioSocialDetalle`
 * — ver docblock de la migración que crea esta tabla.
 *
 * Incremento 1: solo almacena el antecedente. `LiquidacionCeseService`
 * todavía no la consulta (Incremento 2).
 */
#[Fillable([
    'empresa_id', 'colaborador_id', 'importacion_detalle_id',
    'tipo', 'anio', 'periodo', 'fecha_periodo_inicio', 'fecha_periodo_fin', 'fecha_pago_deposito',
    'importe_bruto', 'importe_pagado', 'estado', 'version', 'es_version_vigente',
    'fecha_ingreso_vinculo', 'fecha_fin_vinculo', 'fecha_corte',
    'origen', 'referencia_externa', 'observaciones',
    'aprobado_por', 'aprobado_at', 'anulado_por', 'anulado_at', 'motivo_anulacion',
])]
class BeneficioSocialHistorico extends Model
{
    /** @use HasFactory<BeneficioSocialHistoricoFactory> */
    use HasFactory;

    public const TIPOS = [
        'gratificacion_julio', 'gratificacion_diciembre',
        'cts_mayo', 'cts_noviembre',
        'gratificacion_trunca', 'cts_trunca',
    ];

    public const ESTADOS = ['borrador', 'aprobado', 'pagado', 'depositado', 'anulado'];

    /** Estados que implican un desembolso ya confirmado (no una mera provisión/cálculo). */
    private const ESTADOS_CON_DESEMBOLSO = ['pagado', 'depositado'];

    protected $table = 'beneficios_sociales_historicos';

    protected static function newFactory(): BeneficioSocialHistoricoFactory
    {
        return BeneficioSocialHistoricoFactory::new();
    }

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'fecha_periodo_inicio' => 'date',
            'fecha_periodo_fin' => 'date',
            'fecha_pago_deposito' => 'date',
            'importe_bruto' => 'decimal:2',
            'importe_pagado' => 'decimal:2',
            'version' => 'integer',
            'es_version_vigente' => 'boolean',
            'fecha_ingreso_vinculo' => 'date',
            'fecha_fin_vinculo' => 'date',
            'fecha_corte' => 'date',
            'aprobado_at' => 'datetime',
            'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    public function importacionDetalle(): BelongsTo
    {
        return $this->belongsTo(NominaImportacionHistoricaDetalle::class, 'importacion_detalle_id');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    /**
     * Data de negocio pura (no fórmula de liquidación): una fila en
     * `borrador`/`aprobado` es una provisión o un cálculo todavía sin
     * desembolso confirmado y por lo tanto NO debe llevar `importe_pagado`;
     * una fila `pagado`/`depositado` representa un desembolso real y SÍ
     * debe traerlo. Ningún índice SQL puede expresar esta dependencia entre
     * `estado` e `importe_pagado` de forma portable entre MySQL y SQLite —
     * ver informe de Incremento 1. Todavía no se invoca automáticamente
     * (no hay observers/events en este incremento): queda lista para que el
     * servicio de Incremento 2 la use antes de persistir.
     */
    public function esConsistente(): bool
    {
        $tieneDesembolso = in_array($this->estado, self::ESTADOS_CON_DESEMBOLSO, true);

        return $tieneDesembolso ? $this->importe_pagado !== null : $this->importe_pagado === null;
    }

    public function scopeParaEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->where('empresa_id', $empresaId);
    }

    public function scopeParaColaborador(Builder $query, int $colaboradorId): Builder
    {
        return $query->where('colaborador_id', $colaboradorId);
    }

    public function scopeParaVinculo(Builder $query, int $colaboradorId, string $fechaIngresoVinculo): Builder
    {
        return $query->where('colaborador_id', $colaboradorId)->whereDate('fecha_ingreso_vinculo', $fechaIngresoVinculo);
    }

    /**
     * Filtra por `es_version_vigente` (no por `estado`): una fila anulada
     * deja de ser vigente, pero también deja de serlo la versión ANTERIOR
     * de una corrección — `estado != 'anulado'` por sí solo no distinguía
     * ambos casos.
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('es_version_vigente', true);
    }

    public function scopeAprobados(Builder $query): Builder
    {
        return $query->whereIn('estado', ['aprobado', 'pagado', 'depositado']);
    }

    public function scopeHastaFechaCorte(Builder $query, string $fecha): Builder
    {
        return $query->whereDate('fecha_corte', '<=', $fecha);
    }
}
